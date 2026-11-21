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
    /** Odczyt tekstu z etykiety w przeglądarce (Tesseract.js, język polski), pliki z jsdelivr sprawdzane przez integrity. */
    private const TESS = [
        'main' => ['https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js', 'sha384-GJqSu7vueQ9qN0E9yLPb3Wtpd7OrgK8KmYzC8T1IysG1bcvxvIO4qtYR/D3A991F'],
        'worker' => ['https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/worker.min.js', 'sha384-zxn+VqofFzXpH99dUb3fa4ywoSBQJPy6/6oEaJHPlblZXr53a0g1Jl6720vgSzB7'],
        'simd' => ['https://cdn.jsdelivr.net/npm/tesseract.js-core@5.1.1/tesseract-core-simd-lstm.wasm.js', 'sha384-YM1G+zaWYy9VSRP2C101LCgzh7n3Hqb6OEm9qDoZYgCADsni3nnECskC3WialQB7'],
        'core' => ['https://cdn.jsdelivr.net/npm/tesseract.js-core@5.1.1/tesseract-core-lstm.wasm.js', 'sha384-EKWY5JDwphQoyxhvYU7eaCcyQ0EdV6fZK3L2vzfZcv2ichRdNd6pSnyWPNv6fMuR'],
        'pol' => ['https://cdn.jsdelivr.net/npm/@tesseract.js-data/pol@1.0.0/4.0.0_best_int/pol.traineddata.gz', 'sha384-2CKxwXI7//OlArAuo2Sr69vczHCUSs4kElZB4CGRR5uvTF5x7hwaUeJ0Woiboz/5'],
    ];

    /** Producent z ustawień Produkcji (pole „Dane producenta”, gdy etykieta nie ma własnego). */
    public static string $maker = '';

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
        return '.lb{position:relative;box-sizing:border-box;overflow:hidden;flex:none;background:#fff}.lb-in{position:absolute}.lb-in>img{position:absolute;inset:0;width:100%;height:100%;display:block}'
            . '.lb-bar{position:absolute;display:block;background:#fff}.lb-bar svg{display:block;width:100%;height:100%}'
            . '.lb-f{position:absolute;display:flex;align-items:center;white-space:nowrap;overflow:hidden;line-height:1;font-family:Arial,Helvetica,sans-serif;font-weight:700;color:#000}'
            . '.lb-m{display:block;white-space:pre;line-height:1.15;font-weight:400}.lb-mw{white-space:pre-wrap;overflow-wrap:anywhere}.lb-o{position:absolute;inset:0;box-sizing:border-box;border:.3mm solid #000;pointer-events:none;z-index:1}.lb-o-circle{border-radius:50%}.lb-o-round{border-radius:3mm}';
    }

    /** Jedna etykieta: plik + dane w zaznaczonych miejscach. @param array<string,string> $values lot/produced/expires */
    public static function render(array $l, string $img, array $values): string
    {
        $w = (float) $l['width_mm'];
        $h = (float) $l['height_mm'];
        $just = ['left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end'][$l['boxes']['align']] ?? 'flex-start';
        // Obrys z obszarem: etykieta przycięta do obszaru obrysu (biały margines pliku nie idzie na arkusz).
        $o = (string) ($l['boxes']['outline'] ?? '');
        $f = $o !== '' && $o !== 'none' ? ($l['boxes']['frame'] ?? null) : null;
        [$fx, $fy, $fw, $fh] = is_array($f) ? $f : [0.0, 0.0, 1.0, 1.0];
        $html = '<div class="lb" style="width:' . self::mm($w * $fw) . 'mm;height:' . self::mm($h * $fh) . 'mm">'
            . '<div class="lb-in" style="left:' . self::pct(-$fx / $fw) . ';top:' . self::pct(-$fy / $fh) . ';width:' . self::pct(1 / $fw) . ';height:' . self::pct(1 / $fh) . '">'
            . '<img src="' . U::h($img) . '" alt="">';
        foreach (array_keys(Store::LABEL_FIELDS) as $k) {
            $b = $l['boxes'][$k] ?? null;
            $v = match ($k) {
                'qr' => self::qrUrl($l, $values),
                'maker' => Store::makerText((string) ($l['boxes']['maker_text'] ?? '')) ?: Store::makerText((string) ($values['maker'] ?? self::$maker)),
                default => trim((string) ($values[$k] ?? '')),
            };
            if ($b === null || $v === '') {
                continue;
            }
            if ($k === 'qr') {
                $svg = self::qrSvg($v);
                if ($svg !== '') {
                    $html .= '<span class="lb-bar lb-qr" style="left:' . self::pct($b[0]) . ';top:' . self::pct($b[1]) . ';width:' . self::pct($b[2]) . ';height:' . self::pct($b[3]) . '">' . $svg . '</span>';
                }
                continue;
            }
            if ($k === 'ean_bar') {
                $svg = self::eanSvg($v);
                if ($svg !== '') {
                    $html .= '<span class="lb-bar" style="left:' . self::pct($b[0]) . ';top:' . self::pct($b[1]) . ';width:' . self::pct($b[2]) . ';height:' . self::pct($b[3]) . '">' . $svg . '</span>';
                }
                continue;
            }
            if ($k === 'maker') {
                $lines = !empty($l['boxes']['maker_wrap']) ? 1 : max(1, substr_count($v, "\n") + 1);
                $html .= '<span class="lb-f lb-m' . (!empty($l['boxes']['maker_wrap']) ? ' lb-mw' : '') . '" style="left:' . self::pct($b[0]) . ';top:' . self::pct($b[1]) . ';width:' . self::pct($b[2]) . ';height:' . self::pct($b[3])
                    . ';font-size:' . self::mm($b[3] * $h / $lines / 1.2) . 'mm;text-align:' . ['left' => 'left', 'center' => 'center', 'right' => 'right'][$l['boxes']['align']] . '">' . U::h($v) . '</span>';
                continue;
            }
            $html .= '<span class="lb-f lb-' . $k . '" style="left:' . self::pct($b[0]) . ';top:' . self::pct($b[1]) . ';width:' . self::pct($b[2]) . ';height:' . self::pct($b[3])
                . ';font-size:' . self::mm($b[3] * $h * 0.8) . 'mm;justify-content:' . $just . '">' . U::h($v) . '</span>';
        }
        $html .= '</div>';
        if ($o !== '' && $o !== 'none') {
            $html .= '<span class="lb-o lb-o-' . U::h($o) . '"></span>';
        }
        return $html . '</div>';
    }

    /** Kod kreskowy EAN-13 (12 cyfr = dopisuje cyfrę kontrolną, 8 cyfr = EAN-8) jako SVG bez marginesu. Zły kod = ''. */
    public static function eanSvg(string $code): string
    {
        $d = preg_replace('/\D/', '', $code) ?? '';
        $check = static function (string $body): int {
            $sum = 0;
            foreach (array_reverse(str_split($body)) as $i => $c) {
                $sum += (int) $c * ($i % 2 === 0 ? 3 : 1);
            }
            return (10 - $sum % 10) % 10;
        };
        if (strlen($d) === 12) {
            $d .= $check($d);
        }
        if (!in_array(strlen($d), [8, 13], true) || $check(substr($d, 0, -1)) !== (int) substr($d, -1)) {
            return '';
        }
        $L = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];
        $G = ['0100111', '0110011', '0011011', '0100001', '0011101', '0111001', '0000101', '0010001', '0001001', '0010111'];
        $R = array_map(static fn(string $x): string => strtr($x, '01', '10'), $L);
        if (strlen($d) === 13) {
            $parity = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'][(int) $d[0]];
            $bits = '101';
            for ($i = 1; $i <= 6; $i++) {
                $bits .= ($parity[$i - 1] === 'L' ? $L : $G)[(int) $d[$i]];
            }
            $bits .= '01010';
            for ($i = 7; $i <= 12; $i++) {
                $bits .= $R[(int) $d[$i]];
            }
        } else {
            $bits = '101';
            for ($i = 0; $i < 4; $i++) {
                $bits .= $L[(int) $d[$i]];
            }
            $bits .= '01010';
            for ($i = 4; $i < 8; $i++) {
                $bits .= $R[(int) $d[$i]];
            }
        }
        $bits .= '101';
        $rects = '';
        preg_match_all('/1+/', $bits, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as [$run, $x]) {
            $rects .= '<rect x="' . $x . '" width="' . strlen($run) . '" height="1"/>';
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . strlen($bits) . ' 1" preserveAspectRatio="none" shape-rendering="crispEdges" role="img" aria-label="EAN ' . $d . '">' . $rects . '</svg>';
    }

    /** Zmniejsza czcionkę pola, gdy tekst nie mieści się w szerokości prostokąta. */
    public static function fitScript(): string
    {
        return '<script>(function(){function fit(){document.querySelectorAll(".lb-f").forEach(function(e){var f=parseFloat(e.style.fontSize);e.dataset.fs=e.dataset.fs||f;'
            . 'f=parseFloat(e.dataset.fs);e.style.fontSize=f+"mm";var n=0;while((e.scrollWidth>e.clientWidth+1||e.scrollHeight>e.clientHeight+1)&&n++<60){f*=0.94;e.style.fontSize=f.toFixed(3)+"mm";}});}'
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
            'expires' => U::date($b['expires_at'] ?? date('Y-m-d', strtotime('+12 months')))] + self::eanValues((string) ($p['ean'] ?? '') !== '' ? (string) $p['ean'] : '5901234123457');
    }

    /** @return array{ean:string,ean_bar:string} */
    private static function eanValues(string $ean): array
    {
        $ean = trim($ean);
        return ['ean' => $ean, 'ean_bar' => $ean];
    }

    /** Dane partii do etykiety. @return array<string,string> */
    /** Adres w kodzie QR: wpisany przy etykiecie, {partia} = numer partii. Puste = bez kodu. (Później: link do instrukcji produktu.) */
    public static function qrUrl(array $l, array $values): string
    {
        return str_ireplace('{partia}', rawurlencode((string) ($values['lot'] ?? '')), (string) ($l['boxes']['qr_url'] ?? ''));
    }

    public static function qrSvg(string $url): string
    {
        if ($url === '') {
            return '';
        }
        try {
            return QrCode::encode($url)->svg(0, 'QR ' . $url);
        } catch (\Throwable) {
            return '';
        }
    }

    public static function values(?array $batch, string $code = '', ?array $crm = null): array
    {
        return self::eanValues((string) ($crm['ean'] ?? '')) + ['lot' => $batch !== null ? (string) $batch['code'] : $code,
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
            $crm = $s->crmForBatch($b);
            $l = $crm !== null ? $s->labelFor((int) $crm['id']) : null;
            if ($l === null) {
                $missing[] = $b['code'] . ' (' . ($crm === null ? ($b['product_id'] === null ? 'nie wybrano produktu z Magazynu' : 'brak produktu o SKU wyrobu') : 'produkt bez etykiety') . ')';
                continue;
            }
            $n = isset($nIn[$b['id']]) ? max(0, min(500, (int) $nIn[$b['id']])) : max(1, (int) $b['qty']);
            $n = min($n, max(0, 1000 - $total));
            $total += $n;
            $items[] = [$b, $l, $n, $crm];
            $form .= '<label class="it"><b>' . U::h($b['code']) . '</b> <span>' . U::h($crm['name']) . '</span> <input type="number" name="n[' . U::h($b['id']) . ']" min="0" max="500" value="' . $n . '" style="width:64px"> szt.</label>';
        }
        $form .= self::skipInput() . ' <button>Pokaż</button></form>';
        $sheet = $items !== [] ? self::blanks($items[0][1]) : '';
        foreach ($items as [$b, $l, $n, $crm]) {
            $sheet .= str_repeat(self::render($l, self::fileUrl($l), self::values($b, '', $crm)), $n);
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
        $l = $s->labelFor($pid);
        $edit = U::url('etykieta_edytuj', ['produkt' => $pid]);
        $shared = $l['shared_from'] ?? null;
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
            . ($shared !== null
                ? ' · wspólna z wariantem <a href="product_view.php?id=' . (int) $shared['id'] . '">' . U::h($shared['name']) . ($shared['sku'] !== '' ? ' (' . U::h($shared['sku']) . ')' : '') . '</a>'
                    . ($ctx['canEdit'] ? ' · <a href="' . U::h($edit) . '">Wgraj osobną dla tego wariantu</a>' : '')
                : ($ctx['canEdit'] ? ' · <a href="' . U::h($edit) . '">Zmień plik albo miejsce numeru</a>' : '')) . '</p>'
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
        $colors = ['lot' => '#d9480f', 'produced' => '#1971c2', 'expires' => '#2f9e44', 'ean_bar' => '#7048e8', 'ean' => '#c2255c', 'qr' => '#0c8599', 'maker' => '#e67700'];
        $hints = ['lot' => 'wymagane', 'produced' => 'data z partii', 'expires' => 'data z partii', 'ean_bar' => 'z karty produktu', 'ean' => 'z karty produktu', 'qr' => 'adres poniżej', 'maker' => 'tekst poniżej'];
        $opts = [
            'qr' => U::input('qr_url', (string) ($boxes['qr_url'] ?? ''), 'Kod QR prowadzi do', 'id="lb-qr" inputmode="url" placeholder="https://…"', '{partia} = numer partii'),
            'maker' => '<label>Treść<textarea name="maker_text" id="lb-maker" rows="5" wrap="off" placeholder="' . U::h(self::$maker !== '' ? self::$maker : "Nazwa firmy\nadres\nkontakt") . '">' . U::h((string) ($boxes['maker_text'] ?? '')) . '</textarea>'
                . '<small>Enter = nowa linia. Puste = producent z ustawień Produkcji.</small></label>'
                . '<div class="lb-seg" role="radiogroup" aria-label="Układ tekstu producenta">'
                . '<label><input type="radio" name="lb_mw" value="0"' . (empty($boxes['maker_wrap']) ? ' checked' : '') . '><span>Wiersze jak wpisane</span></label>'
                . '<label><input type="radio" name="lb_mw" value="1"' . (!empty($boxes['maker_wrap']) ? ' checked' : '') . '><span>Zawijaj w polu</span></label></div>'
                . '<input type="hidden" id="lb-mwrap" value="' . (!empty($boxes['maker_wrap']) ? '1' : '0') . '">',
        ];
        $fields = '';
        foreach (Store::LABEL_FIELDS as $k => $label) {
            $fields .= '<div class="lb-row" data-k="' . $k . '"><label class="lb-rl"><input type="radio" name="lb_field" value="' . $k . '"' . ($k === 'lot' ? ' checked' : '') . '>'
                . '<span class="lb-dot" style="background:' . $colors[$k] . '"></span><span class="lb-nm">' . $label . '<small>' . $hints[$k] . '</small></span><span class="lb-st"></span></label>'
                . (isset($opts[$k]) ? '<div class="lb-opts">' . $opts[$k] . '</div>' : '') . '</div>';
        }
        $noOl = ($boxes['outline'] ?? 'none') === 'none';
        $cfg = json_encode(['img' => $l !== null ? self::fileUrl($l) : '', 'pxW' => (int) ($l['px_w'] ?? 0), 'pxH' => (int) ($l['px_h'] ?? 0),
            'sample' => $sample, 'bar' => self::eanSvg($sample['ean_bar']), 'qr' => self::qrSvg('https://example.com/instrukcja'), 'maker' => self::$maker, 'names' => Store::LABEL_FIELDS, 'pdfjs' => self::PDFJS, 'sri' => self::PDFJS_SRI,
            'tess' => self::TESS, 'check' => self::checkData($s, $p)], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
        $html = U::style() . '<style>' . self::css() . '
.lb-ed-wrap{background:var(--surface-2);border-radius:8px;padding:12px;overflow:auto}
.lb-ed{position:relative;display:inline-block;max-width:100%;touch-action:none;user-select:none;cursor:crosshair;background:#fff;box-shadow:0 0 0 1px var(--line)}
.lb-ed img{display:block;max-width:100%;max-height:70vh;pointer-events:none}
.lb-ed .lb-box{position:absolute;border:2px solid #d9480f;background:rgba(217,72,15,.12);box-sizing:border-box;display:flex;align-items:center;white-space:nowrap;font:700 12px/1 Arial,sans-serif;color:#000;cursor:move}.lb-ed .lb-box b{display:block;overflow:hidden;max-width:100%;pointer-events:none}
.lb-ed .lb-box.on{outline:2px dashed #000;outline-offset:2px}.lb-ed .lb-h{position:absolute;right:-7px;bottom:-7px;width:12px;height:12px;background:#fff;border:2px solid #000;border-radius:2px;cursor:nwse-resize;display:none}.lb-ed .lb-box.on .lb-h{display:block}
.lb-ed .lb-box[data-k=produced]{border-color:#1971c2;background:rgba(25,113,194,.12)}.lb-ed .lb-box[data-k=expires]{border-color:#2f9e44;background:rgba(47,158,68,.12)}.lb-ed .lb-box[data-k=ean_bar]{border-color:#7048e8;background:rgba(112,72,232,.12)}.lb-ed .lb-box[data-k=ean]{border-color:#c2255c;background:rgba(194,37,92,.12)}.lb-ed .lb-box[data-k=qr]{border-color:#0c8599;background:rgba(12,133,153,.12)}.lb-ed .lb-box[data-k=maker]{border-color:#e67700;background:rgba(230,119,0,.12);align-items:flex-start}.lb-ed .lb-box[data-k=maker] b{white-space:pre;font-weight:400;line-height:1.15}
.lb-dot{display:inline-block;width:12px;height:12px;border-radius:3px}
.lb-ed .lb-box.lb-fr{border:2px solid #000;background:transparent;pointer-events:none}.lb-ed .lb-box.lb-fr.on{pointer-events:auto;background:rgba(0,0,0,.04)}.lb-ed .lb-fr-circle{border-radius:50%}
.lb-check{list-style:none;margin:8px 0 0;padding:0;font-size:14px}.lb-check li{padding:4px 0}.lb-check .ok::before{content:"✓ ";color:var(--ok-ink,#2b8a3e);font-weight:700}.lb-check .warn::before{content:"⚠ ";color:var(--warn-ink)}.lb-check .warn{color:var(--warn-ink)}.lb-check .info::before{content:"ℹ ";color:var(--ink-3)}
.lb-empty{padding:40px 20px;color:var(--ink-3);text-align:center}
#lb-form{max-width:1280px}
.lb-help summary{cursor:pointer;color:var(--ink-2);font-size:14px;font-weight:600}.lb-help p{margin:6px 0 0}
.lb-layout{display:grid;gap:16px;grid-template-columns:1fr;align-items:start}
@media(min-width:1000px){.lb-layout{grid-template-columns:minmax(0,1fr) 360px}}
.lb-side{order:2;display:flex;flex-direction:column;gap:12px;min-width:0}
.lb-sec{border:1px solid var(--line);border-radius:10px;padding:12px;display:flex;flex-direction:column;gap:10px;background:var(--surface,#fff)}
.lb-sec h4{margin:0;font-size:13px;text-transform:uppercase;letter-spacing:.04em;color:var(--ink-3)}
.lb-pair{display:grid;grid-template-columns:1fr 1fr;gap:8px}.lb-pair[hidden]{display:none!important}#lb-form .lb-pair.lb-fsize{display:grid}#lb-form .lb-pair.lb-fsize[hidden]{display:none!important}#lb-fh-w[hidden]{display:none!important}
.lb-rows{display:flex;flex-direction:column;gap:4px}
.lb-row{border:1px solid transparent;border-radius:8px}.lb-row.on{border-color:var(--line);background:var(--surface-2)}.lb-row[hidden]{display:none}
#lb-form .lb-rl{flex-direction:row;align-items:center;gap:10px;padding:8px 10px;cursor:pointer;color:var(--ink);font-size:14px}
.lb-rl input{margin:0;flex:none}.lb-nm{display:flex;flex-direction:column;flex:1;min-width:0}.lb-nm small{font-size:12px}
.lb-st{font-size:12px;font-weight:600;color:var(--ink-3);white-space:nowrap}.lb-st::after{content:"brak"}.lb-row.set .lb-st{color:var(--ok-ink,#2b8a3e)}.lb-row.set .lb-st::after{content:"✓ na etykiecie"}.lb-row[data-k=frame] .lb-st{display:none}
.lb-opts{display:none;flex-direction:column;gap:8px;padding:0 10px 10px 32px}.lb-row.on .lb-opts{display:flex}
#lb-form .lb-opts textarea{white-space:pre;overflow-x:auto;resize:vertical;min-height:110px;line-height:1.4;font-family:inherit}
.lb-seg{display:flex;border:1px solid var(--line);border-radius:8px;overflow:hidden}
#lb-form .lb-seg label{flex:1;flex-direction:row;gap:0;margin:0;cursor:pointer}.lb-seg input{position:absolute;opacity:0;pointer-events:none}
.lb-seg span{flex:1;text-align:center;padding:7px 6px;font-size:13px;font-weight:600;color:var(--ink-2)}.lb-seg label+label span{border-left:1px solid var(--line)}
.lb-seg input:checked+span{background:var(--ink);color:var(--surface,#fff)}.lb-seg input:focus-visible+span{outline:2px solid var(--ink);outline-offset:-2px}
.lb-btns{display:flex;flex-wrap:wrap;gap:8px}.lb-btns .btn{padding:6px 10px}
</style>' . U::back($back, (string) $p['name'])
            . '<form class="card pr-form" id="lb-form" method="post" enctype="multipart/form-data" action="' . U::h(U::url('etykieta_edytuj', ['produkt' => $pid])) . '">'
            . '<h3 style="margin:0">Etykieta CLP · ' . U::h($p['name']) . '</h3>' . U::csrf($ctx)
            . '<details class="lb-help"><summary>Jak to działa</summary><p class="pr-small">Wgraj gotową etykietę (całą, z piktogramami i tekstami). Najlepiej PNG albo PDF w rozmiarze do druku. '
            . 'Potem zaznacz myszą albo palcem prostokąt, w który system wpisze numer partii. Zaznaczone pole przesuwasz, chwytając je, a rozmiar zmieniasz białym kwadracikiem w rogu. Strzałki na klawiaturze przesuwają pole o 1 piksel (z Shift o 10). „Odczytaj etykietę” sam znajdzie miejsca na numer partii, daty i EAN oraz porówna EAN, UFI i wagę z kartą produktu. Za zgodność treści z CLP odpowiada plik.</p></details>'
            . '<input type="hidden" name="boxes" id="lb-boxes" value="' . U::h(json_encode($boxes)) . '"><input type="hidden" name="source_name" id="lb-src" value="">'
            . '<div class="lb-layout"><aside class="lb-side">'
            . '<section class="lb-sec"><h4>Plik i rozmiar</h4>'
            . '<label>' . ($l !== null ? 'Nowy plik (zastąpi obecny)' : 'Plik etykiety') . '<input type="file" name="plik" id="lb-file" accept="image/png,image/jpeg,image/webp,application/pdf,.pdf"' . ($l === null ? ' required' : '') . '>'
            . '<small>' . ($l !== null ? 'Teraz: ' . U::h($l['source_name']) . ', ' . (int) $l['px_w'] . '×' . (int) $l['px_h'] . ' px' : 'PNG, JPG, WEBP albo PDF (pierwsza strona)') . '</small></label>'
            . '<div class="lb-pair">' . U::input('width_mm', $w, 'Szerokość (mm)', 'id="lb-w" inputmode="decimal" required')
            . U::input('height_mm', $h, 'Wysokość (mm)', 'id="lb-h" inputmode="decimal" placeholder="z pliku"') . '</div></section>'
            . '<section class="lb-sec"><h4>Obrys (linia cięcia)</h4>'
            . U::select('outline', Store::OUTLINES, (string) ($boxes['outline'] ?? 'none'), 'Kształt', 'id="lb-outline"')
            . '<div class="lb-pair lb-fsize" id="lb-fsize"' . ($noOl ? ' hidden' : '') . '><label><span id="lb-fw-l">Szerokość (mm)</span><input id="lb-fw" inputmode="decimal"></label>'
            . '<label id="lb-fh-w">Wysokość (mm)<input id="lb-fh" inputmode="decimal"></label></div>'
            . '<div class="lb-btns"><button type="button" class="btn secondary" id="lb-fit"' . ($noOl ? ' hidden' : '') . '>Dopasuj do etykiety</button></div>'
            . '<div class="lb-row" data-k="frame" id="lb-pick-frame"' . ($noOl ? ' hidden' : '') . '><label class="lb-rl"><input type="radio" name="lb_field" value="frame"><span class="lb-dot" style="background:#000"></span><span class="lb-nm">Przesuń obrys na podglądzie<small>chwyć i przeciągnij, róg = rozmiar</small></span><span class="lb-st"></span></label></div>'
            . '</section>'
            . '<section class="lb-sec"><h4>Pola wstawiane przez system</h4>'
            . '<p class="pr-small" style="margin:0">Wybierz pole i zaznacz jego miejsce na podglądzie.</p>'
            . '<div class="lb-rows">' . $fields . '</div>'
            . U::select('align', ['left' => 'do lewej', 'center' => 'na środku', 'right' => 'do prawej'], (string) ($posted['align'] ?? $boxes['align']), 'Wyrównanie tekstu w polach', 'id="lb-align"')
            . '<div class="lb-btns"><button type="button" class="btn secondary" id="lb-clear">Usuń wybrane pole</button>'
            . '<button type="button" class="btn secondary" id="lb-ocr">Odczytaj etykietę</button></div>'
            . '</section></aside>'
            . '<div class="lb-main"><div class="lb-ed-wrap"><div class="lb-ed" id="lb-ed">' . ($l !== null ? '<img id="lb-img" src="' . U::h(self::fileUrl($l)) . '" alt="Etykieta">' : '<div class="lb-empty" id="lb-none">Wybierz plik, żeby zobaczyć podgląd.</div>') . '</div></div>'
            . '<p class="pr-small" id="lb-msg" style="margin:8px 0 0"></p><ul class="lb-check" id="lb-check" hidden></ul></div></div>'
            . '<div class="pr-actions"><button class="btn" type="submit">Zapisz</button><a class="btn secondary" href="' . U::h($back) . '">Anuluj</a>'
            . ($l !== null ? '<button class="btn secondary" type="submit" name="delete" value="1" formnovalidate style="margin-left:auto;color:var(--danger-ink)" onclick="return confirm(\'Usunąć etykietę tego produktu?\')">Usuń etykietę</button>' : '')
            . '</div></form>';
        return $html . '<script>window.LB=' . $cfg . ';</script><script>' . self::editorJs() . '</script>';
    }

    /** Dane do kontroli odczytanej etykiety: EAN produktu, waga z karty wyrobu, UFI przypisanych zapachów, liczba innych wariantów. */
    private static function checkData(Store $s, array $p): array
    {
        $out = ['ean' => trim((string) ($p['ean'] ?? '')), 'weight' => null, 'ufis' => [], 'variants' => 0];
        try {
            $w = $s->one('SELECT weight_g FROM ext_prod_products WHERE sku = ? AND archived = 0 AND weight_g IS NOT NULL', [(string) ($p['sku'] ?? '')]);
            $out['weight'] = $w !== null && (float) $w['weight_g'] > 0 ? (float) $w['weight_g'] : null;
        } catch (\PDOException) {
        }
        try {
            foreach ($s->all('SELECT f.ufi FROM ext_zap_links l JOIN ext_zap_fragrances f ON f.id = l.fragrance_id WHERE l.product_id = ?', [(int) $p['id']]) as $f) {
                foreach (preg_split('/[\s,;]+/', strtoupper((string) $f['ufi'])) ?: [] as $u) {
                    if (preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $u)) {
                        $out['ufis'][] = $u;
                    }
                }
            }
        } catch (\PDOException) {
        }
        if ((int) ($p['woo_product_id'] ?? 0) > 0) {
            try {
                $out['variants'] = (int) ($s->one('SELECT COUNT(*) AS n FROM products WHERE woo_product_id = ? AND id <> ?', [(int) $p['woo_product_id'], (int) $p['id']])['n'] ?? 0);
            } catch (\PDOException) {
            }
        }
        $out['ufis'] = array_values(array_unique($out['ufis']));
        return $out;
    }

    private static function editorJs(): string
    {
        return <<<'JS'
(function(){
var C=window.LB,ed=document.getElementById('lb-ed'),file=document.getElementById('lb-file'),W=document.getElementById('lb-w'),H=document.getElementById('lb-h'),
 hid=document.getElementById('lb-boxes'),msg=document.getElementById('lb-msg'),src=document.getElementById('lb-src'),OL=document.getElementById('lb-outline'),
 chk=document.getElementById('lb-check'),boxes={};
try{boxes=JSON.parse(hid.value)||{};}catch(e){boxes={};}
var pxW=C.pxW,pxH=C.pxH,hAuto=!H.value,lotInFile='',fileNote='';
function field(){var r=document.querySelector('input[name=lb_field]:checked');return r?r.value:'lot';}
function img(){return document.getElementById('lb-img');}
function outline(){return OL.value;}
function mwrap(){return document.getElementById('lb-mwrap').value==='1';}
function mtext(){return document.getElementById('lb-maker').value.split('\n').map(function(x){return x.trim();}).join('\n').trim();}
function save(){var o={align:document.getElementById('lb-align').value,outline:outline(),qr_url:document.getElementById('lb-qr').value.trim(),maker_text:mtext(),maker_wrap:mwrap()};for(var k in C.names){if(boxes[k])o[k]=boxes[k];}
 if(outline()!=='none'&&boxes.frame)o.frame=boxes.frame;hid.value=JSON.stringify(o);}
function num(v){return parseFloat(String(v).replace(',','.'));}
function wmm(){return num(W.value)||0;}
function hmm(){return num(H.value)||(pxW?wmm()*pxH/pxW:0);}
function mmTxt(v){return (Math.round(v*10)/10+'').replace('.',',');}
function info(){var w=wmm(),t=[];if(!boxes.lot)t.push('Zaznacz prostokąt na numer partii.');
 if(pxW&&w>0){var dpi=Math.round(pxW/(w/25.4));t.push('Rozdzielczość: '+dpi+' dpi'+(dpi<200?' - mało, wydruk może być nieostry. Wyeksportuj plik w większej rozdzielczości (najlepiej 300 dpi).':'.'));}
 if(boxes.qr&&!/^https?:\/\/\S+$/i.test(document.getElementById('lb-qr').value.trim()))t.push('Wpisz adres (https://…) w „Kod QR prowadzi do”, inaczej kod QR się nie wydrukuje.');
 if(outline()!=='none'&&w>0){var f=boxes.frame||[0,0,1,1];t.push('Etykieta po przycięciu do obrysu: '+mmTxt(f[2]*w)+' × '+mmTxt(f[3]*hmm())+' mm.');}
 msg.textContent=t.join(' ');msg.style.color=(!boxes.lot||(pxW&&w>0&&pxW/(w/25.4)<200))?'var(--warn-ink)':'';}
function handle(d){var hd=document.createElement('i');hd.className='lb-h';d.appendChild(hd);}
function place(d,b){d.style.left=b[0]*100+'%';d.style.top=b[1]*100+'%';d.style.width=b[2]*100+'%';d.style.height=b[3]*100+'%';}
function draw(){ed.querySelectorAll('.lb-box').forEach(function(b){b.remove();});var i=img();if(!i)return;
 var ol=outline();document.getElementById('lb-pick-frame').hidden=ol==='none';document.getElementById('lb-fit').hidden=ol==='none';document.getElementById('lb-fsize').hidden=ol==='none';
 document.getElementById('lb-fw-l').textContent=ol==='circle'?'Średnica (mm)':'Szerokość (mm)';document.querySelectorAll('.lb-row[data-k]').forEach(function(r){var k=r.dataset.k;r.classList.toggle('on',k===field());r.classList.toggle('set',!!boxes[k]);});document.getElementById('lb-fh-w').hidden=ol==='circle';
 if(ol!=='none'&&wmm()>0){var ff=boxes.frame||[0,0,1,1],FW=document.getElementById('lb-fw'),FH=document.getElementById('lb-fh');
  if(document.activeElement!==FW)FW.value=mmTxt(ff[2]*wmm());if(document.activeElement!==FH)FH.value=mmTxt(ff[3]*hmm());}
 if(ol==='none'&&field()==='frame')pick('lot');
 if(ol!=='none'){var f=boxes.frame||[0,0,1,1],fd=document.createElement('div');fd.className='lb-box lb-fr lb-fr-'+ol;fd.dataset.k='frame';fd.title='Obrys';place(fd,f);
  if(ol==='round'&&wmm()>0)fd.style.borderRadius=(3*i.clientWidth/wmm())+'px';if(field()==='frame')fd.classList.add('on');handle(fd);ed.appendChild(fd);}
 for(var k in C.names){var b=boxes[k];if(!b)continue;var d=document.createElement('div');d.className='lb-box';d.dataset.k=k;place(d,b);
  d.style.justifyContent={left:'flex-start',center:'center',right:'flex-end'}[document.getElementById('lb-align').value];
  var fs=b[3]*i.clientHeight*0.8;d.style.fontSize=fs+'px';var tx=document.createElement('b');d.title=C.names[k];d.appendChild(tx);if(k===field())d.classList.add('on');handle(d);ed.appendChild(d);
  if(k==='qr'){tx.innerHTML=C.qr||'';tx.style.cssText='width:100%;height:100%;background:#fff';var sq=tx.querySelector('svg');if(sq){sq.style.width='100%';sq.style.height='100%';sq.style.display='block';}continue;}
  if(k==='ean_bar'){tx.innerHTML=C.bar||'';tx.style.cssText='width:100%;height:100%;background:#fff';var sv=tx.querySelector('svg');if(sv){sv.style.width='100%';sv.style.height='100%';sv.style.display='block';}continue;}
  if(k==='maker'){var mt=mtext()||C.maker||'Nazwa producenta\nadres\nkontakt';tx.textContent=mt;fs=b[3]*i.clientHeight/(mwrap()?1:Math.max(1,mt.split('\n').length))/1.2;d.style.fontSize=fs+'px';
   tx.style.whiteSpace=mwrap()?'pre-wrap':'pre';tx.style.overflowWrap=mwrap()?'anywhere':'normal';tx.style.textAlign=document.getElementById('lb-align').value;tx.style.width='100%';var nm=0;while((tx.scrollWidth>d.clientWidth+1||tx.scrollHeight>d.clientHeight+1)&&nm++<60){fs*=0.94;d.style.fontSize=fs+'px';}continue;}
  tx.textContent=C.sample[k]||'';var n=0;while(tx.scrollWidth>d.clientWidth+1&&n++<60){fs*=0.94;d.style.fontSize=fs+'px';}}
 save();info();}
function setImg(url,w,h,after){var n=document.getElementById('lb-none');if(n)n.remove();var i=img();if(!i){i=document.createElement('img');i.id='lb-img';i.alt='Etykieta';ed.appendChild(i);}
 pxW=w;pxH=h;i.onload=function(){if(outline()!=='none')fitFrame();draw();if(after)after();};i.src=url;if(hAuto&&wmm()>0)H.value='';}
var start=null,mode='',orig=null,mk='';
function pt(e){var r=img().getBoundingClientRect();return [Math.max(0,Math.min(1,(e.clientX-r.left)/r.width)),Math.max(0,Math.min(1,(e.clientY-r.top)/r.height))];}
function rnd(b){return b.map(function(v){return Math.round(v*10000)/10000;});}
function clamp(v,a,b){return Math.max(a,Math.min(b,v));}
function pick(k){var r=document.querySelector('input[name=lb_field][value="'+k+'"]');if(r)r.checked=true;}
// Okrąg zostaje okręgiem: wysokość (ułamek obrazka) liczona z szerokości w milimetrach.
function keepRound(k,b){if(k!=='frame'||outline()!=='circle'||!(wmm()>0&&hmm()>0))return b;var h=b[2]*wmm()/hmm();
 if(b[1]+h>1){h=1-b[1];b[2]=h*hmm()/wmm();}b[3]=h;return b;}
function setBox(k,b){boxes[k]=rnd(keepRound(k,b));}
ed.addEventListener('pointerdown',function(e){if(!img())return;e.preventDefault();ed.setPointerCapture(e.pointerId);start=pt(e);
 var bx=e.target.closest?e.target.closest('.lb-box'):null,k=bx?bx.dataset.k:'';
 if(bx&&(k==='frame'||boxes[k])){mk=k;pick(mk);orig=(boxes[mk]||[0,0,1,1]).slice();mode=e.target.classList.contains('lb-h')?'size':'move';draw();}else{mode='new';mk=field();}});
ed.addEventListener('pointermove',function(e){if(!start)return;var p=pt(e),dx=p[0]-start[0],dy=p[1]-start[1];
 if(mode==='move'){setBox(mk,[clamp(orig[0]+dx,0,1-orig[2]),clamp(orig[1]+dy,0,1-orig[3]),orig[2],orig[3]]);draw();return;}
 if(mode==='size'&&mk==='frame'&&outline()==='circle'&&wmm()>0&&hmm()>0){
  // Okrąg rośnie od środka, żeby nie uciekał z etykiety.
  var cxm=(orig[0]+orig[2]/2)*wmm(),cym=(orig[1]+orig[3]/2)*hmm(),sd=Math.max(2,orig[2]*wmm()+2*dx*wmm());
  sd=Math.min(sd,2*cxm,2*(wmm()-cxm),2*cym,2*(hmm()-cym));boxes.frame=rnd([(cxm-sd/2)/wmm(),(cym-sd/2)/hmm(),sd/wmm(),sd/hmm()]);draw();return;}
 if(mode==='size'){setBox(mk,[orig[0],orig[1],clamp(orig[2]+dx,0.01,1-orig[0]),clamp(orig[3]+dy,0.01,1-orig[1])]);draw();return;}
 var cur=[Math.min(start[0],p[0]),Math.min(start[1],p[1]),Math.abs(dx),Math.abs(dy)];
 if(cur[2]>0.005&&cur[3]>0.005){setBox(mk,cur);draw();}});
ed.addEventListener('pointerup',function(){start=null;mode='';orig=null;});
document.querySelectorAll('input[name=lb_field]').forEach(function(r){r.addEventListener('change',draw);});
document.addEventListener('keydown',function(e){var k=field(),b=k==='frame'?(boxes.frame||[0,0,1,1]):boxes[k],i=img(),t=e.target;
 if(!b||!i||(t&&/^(INPUT|SELECT|TEXTAREA)$/.test(t.tagName)&&t.type!=='radio'))return;
 var st=(e.shiftKey?10:1),sx=st/i.clientWidth,sy=st/i.clientHeight,d={ArrowLeft:[-sx,0],ArrowRight:[sx,0],ArrowUp:[0,-sy],ArrowDown:[0,sy]}[e.key];
 if(!d)return;e.preventDefault();boxes[k]=rnd([clamp(b[0]+d[0],0,1-b[2]),clamp(b[1]+d[1],0,1-b[3]),b[2],b[3]]);draw();});
document.getElementById('lb-clear').onclick=function(){var k=field();if(k==='frame')fitFrame();else delete boxes[k];draw();};
// Obrys do rysunku: obszar niebiałych pikseli. Okrąg: gdy plik ma narysowany okrąg, bierze go; gdy nie (sam tekst),
// bierze okrąg opisany na tekście, najwyżej tak duży jak plik.
function fitFrame(){var i=img();if(!i||!i.naturalWidth)return;var sc=Math.min(1,1000/Math.max(i.naturalWidth,i.naturalHeight)),cv=document.createElement('canvas');
 cv.width=Math.max(1,Math.round(i.naturalWidth*sc));cv.height=Math.max(1,Math.round(i.naturalHeight*sc));var cx=cv.getContext('2d'),d;
 try{cx.drawImage(i,0,0,cv.width,cv.height);d=cx.getImageData(0,0,cv.width,cv.height).data;}catch(e){boxes.frame=[0,0,1,1];return;}
 var x0=cv.width,y0=cv.height,x1=-1,y1=-1;
 for(var y=0;y<cv.height;y++)for(var x=0;x<cv.width;x++){var o=(y*cv.width+x)*4;if(d[o+3]>16&&(d[o]+d[o+1]+d[o+2])<690){if(x<x0)x0=x;if(x>x1)x1=x;if(y<y0)y0=y;if(y>y1)y1=y;}}
 if(x1<0){boxes.frame=[0,0,1,1];return;}
 var f=[x0/cv.width,y0/cv.height,(x1-x0+1)/cv.width,(y1-y0+1)/cv.height];
 if(outline()==='circle'&&wmm()>0&&hmm()>0){var bw=x1-x0+1,bh=y1-y0+1,drawn=false;
  if(Math.abs(bw-bh)<0.04*Math.max(bw,bh)){var rr=Math.min(bw,bh)/2,ccx=x0+bw/2,ccy=y0+bh/2,hit=0;
   for(var a=0;a<90;a++){var an=a*Math.PI/45,ok=false;for(var q=0.93;q<=1.001&&!ok;q+=0.01){var px=Math.round(ccx+Math.cos(an)*rr*q),py=Math.round(ccy+Math.sin(an)*rr*q);
     if(px>=0&&py>=0&&px<cv.width&&py<cv.height){var oo=(py*cv.width+px)*4;if(d[oo+3]>16&&(d[oo]+d[oo+1]+d[oo+2])<450)ok=true;}}if(ok)hit++;}
   drawn=hit>=63;}
  var side=drawn?Math.max(f[2]*wmm(),f[3]*hmm()):Math.hypot(f[2]*wmm(),f[3]*hmm()),cxm=(f[0]+f[2]/2)*wmm(),cym=(f[1]+f[3]/2)*hmm();
  side=Math.min(side,wmm(),hmm());var l=clamp(cxm-side/2,0,wmm()-side),t=clamp(cym-side/2,0,hmm()-side);f=[l/wmm(),t/hmm(),side/wmm(),side/hmm()];}
 boxes.frame=rnd(f);}
document.getElementById('lb-fit').onclick=function(){fitFrame();pick('frame');draw();};
// Wymiar obrysu wpisany w mm: zmienia obrys wokół jego środka (najwyżej do krawędzi pliku).
function sizeFrame(){var fw=num(document.getElementById('lb-fw').value),fh=outline()==='circle'?fw:num(document.getElementById('lb-fh').value);
 if(!(fw>0&&fh>0&&wmm()>0&&hmm()>0))return;var f=boxes.frame||[0,0,1,1],cxm=(f[0]+f[2]/2)*wmm(),cym=(f[1]+f[3]/2)*hmm();
 fw=Math.min(fw,wmm());fh=Math.min(fh,hmm());var l=clamp(cxm-fw/2,0,wmm()-fw),t=clamp(cym-fh/2,0,hmm()-fh);
 boxes.frame=rnd([l/wmm(),t/hmm(),fw/wmm(),fh/hmm()]);draw();
 var big=num(document.getElementById('lb-fw').value)>wmm()+0.05||(outline()!=='circle'&&num(document.getElementById('lb-fh').value)>hmm()+0.05);
 if(big){msg.textContent='Obrys nie może być większy niż etykieta ('+mmTxt(wmm())+' × '+mmTxt(hmm())+' mm). Zwiększ szerokość etykiety, jeśli plik jest większy.';msg.style.color='var(--warn-ink)';}}
document.getElementById('lb-fw').addEventListener('change',sizeFrame);document.getElementById('lb-fh').addEventListener('change',sizeFrame);
// Rozdzielczość zapisana w pliku (PNG pHYs, JPEG JFIF) = prawdziwy rozmiar w mm.
function fileMm(f,pw,ph){return f.slice(0,262144).arrayBuffer().then(function(b){var v=new DataView(b),dpi=0;
 if(v.byteLength>24&&v.getUint32(0)===0x89504E47){var o=8;while(o+12<=v.byteLength){var n=v.getUint32(o),t=String.fromCharCode(v.getUint8(o+4),v.getUint8(o+5),v.getUint8(o+6),v.getUint8(o+7));
   if(t==='pHYs'&&n>=9){if(v.getUint8(o+16)===1)dpi=v.getUint32(o+8)*0.0254;break;}if(t==='IDAT')break;o+=12+n;}}
 else if(v.byteLength>18&&v.getUint16(0)===0xFFD8&&v.getUint32(6)===0x4A464946){var u=v.getUint8(13),xd=v.getUint16(14);dpi=u===1?xd:(u===2?xd*2.54:0);}
 return dpi>=50&&dpi<=4800?{w:pw/dpi*25.4,h:ph/dpi*25.4,dpi:Math.round(dpi)}:null;}).catch(function(){return null;});}
OL.onchange=function(){if(outline()!=='none'){fitFrame();pick('frame');}draw();};
document.getElementById('lb-qr').addEventListener('input',function(){save();info();});var MK=document.getElementById('lb-maker');function grow(){MK.rows=Math.max(5,MK.value.split('\n').length+1);}grow();MK.addEventListener('input',function(){grow();draw();});document.querySelectorAll('input[name=lb_mw]').forEach(function(r){r.addEventListener('change',function(){document.getElementById('lb-mwrap').value=r.value;draw();});});document.getElementById('lb-align').onchange=draw;W.oninput=function(){draw();};H.oninput=function(){hAuto=!H.value;draw();};
window.addEventListener('resize',draw);
// ---- odczyt etykiety (Tesseract.js w przeglądarce): pola i kontrola z kartą produktu
function get(k,type){return fetch(C.tess[k][0],{integrity:C.tess[k][1],mode:'cors'}).then(function(r){if(!r.ok)throw new Error(k);return type==='buf'?r.arrayBuffer():r.text();});}
var tessP=null;
function loadTess(){if(tessP)return tessP;
 var simd=false;try{simd=WebAssembly.validate(new Uint8Array([0,97,115,109,1,0,0,0,1,5,1,96,0,1,123,3,2,1,0,10,10,1,8,0,65,0,253,15,253,98,11]));}catch(e){}
 var lib=window.Tesseract?Promise.resolve(window.Tesseract):new Promise(function(ok,no){var s=document.createElement('script');s.src=C.tess.main[0];s.integrity=C.tess.main[1];s.crossOrigin='anonymous';
  s.onload=function(){ok(window.Tesseract);};s.onerror=function(){no(new Error('tesseract'));};document.head.appendChild(s);});
 tessP=Promise.all([lib,get(simd?'simd':'core'),get('worker'),get('pol','buf')]).then(function(a){
  return new Promise(function(ok){var fr=new FileReader();fr.onload=function(){ok([a[0],a[1],a[2],String(fr.result).split(',')[1]]);};fr.readAsDataURL(new Blob([a[3]]));});
 }).then(function(a){
  // Rdzeń, worker i słownik w jednym pliku blob: worker nic nie dociąga (importScripts/fetch), więc CSP zostaje bez blob: w script-src i connect-src.
  var shim='var __L="'+a[3]+'";(function(){var f=self.fetch;self.fetch=function(u){if(String(u).indexOf("https://veless.invalid/")===0){var s=atob(__L),b=new Uint8Array(s.length);for(var i=0;i<s.length;i++)b[i]=s.charCodeAt(i);return Promise.resolve(new Response(b));}return f.apply(self,arguments);};})();\n';
  var wurl=URL.createObjectURL(new Blob([shim,a[1],'\n;\n',a[2]],{type:'text/javascript'}));
  // Tryb „rozrzucony tekst” (PSM 11): etykieta to osobne napisy, nie akapity, a duże napisy (waga, nazwa) nie giną.
  return a[0].createWorker('pol',1,{workerPath:wurl,workerBlobURL:false,corePath:wurl+'#.js',langPath:'https://veless.invalid',cacheMethod:'none',gzip:true})
   .then(function(w){return w.setParameters({tessedit_pageseg_mode:'11'}).then(function(){return w;});});
 });tessP.catch(function(){tessP=null;});return tessP;}
function bb(ws){var b={x0:1e9,y0:1e9,x1:-1,y1:-1};ws.forEach(function(w){b.x0=Math.min(b.x0,w.bbox.x0);b.y0=Math.min(b.y0,w.bbox.y0);b.x1=Math.max(b.x1,w.bbox.x1);b.y1=Math.max(b.y1,w.bbox.y1);});return b;}
var FIND={lot:/(nr|numer)\.?\s*partii|partia|\blot\b|batch/i,produced:/data\s*produkcji|wyprodukowano|data\s*prod/i,expires:/najlepiej|zu[żz]y[ćc]|wa[żz]no[śs][ćc]|best\s*before/i,ean:/\bGTIN\b|\bEAN\b/i};
function findField(lines,re,cw,chh){for(var li=0;li<lines.length;li++){var L=lines[li],ws=(L.words||[]).filter(function(w){return w.text.trim()!=='';});
  for(var i=0;i<ws.length;i++){var head=ws.slice(0,i+1).map(function(w){return w.text;}).join(' ');if(!re.test(head))continue;
   var lb=bb(ws.slice(0,i+1)),lh=Math.max(4,lb.y1-lb.y0),vs=i+1;
   for(var j=i;j<Math.min(ws.length,i+3);j++){if(ws[j].text.indexOf(':')>=0){vs=j+1;break;}}
   var lbl=bb(ws.slice(0,vs)),val=[];for(var v=vs;v<ws.length;v++){var prev=v===vs?lbl.x1:ws[v-1].bbox.x1;if(ws[v].bbox.x0-prev>lh*2.5)break;val.push(ws[v]);}
   if(val.length){var vb=bb(val),pad=lh*0.15;return {box:[(vb.x0-pad)/cw,(vb.y0-pad)/chh,(vb.x1-vb.x0+2*pad)/cw,(vb.y1-vb.y0+2*pad)/chh],value:val.map(function(w){return w.text;}).join(' ')};}
   var x=lbl.x1+lh*0.5;return {box:[x/cw,(lbl.y0-lh*0.1)/chh,Math.min(cw*0.25,cw-x)/cw,lh*1.2/chh],value:''};}}
 return null;}
function eanOk(d){if(!/^(\d{8}|\d{13})$/.test(d))return false;var s=0,n=d.length;for(var i=0;i<n-1;i++){s+=(+d[i])*(((n-1-i)%2)?3:1);}return (10-s%10)%10===+d[n-1];}
function ufiN(u){return u.toUpperCase().replace(/O/g,'0').replace(/[IL]/g,'1');}
function row(cls,t){var li=document.createElement('li');li.className=cls;li.textContent=t;chk.appendChild(li);}
function check(text,found){chk.innerHTML='';chk.hidden=false;if(fileNote)row('info',fileNote);var K=C.check,flat=text.replace(/\s+/g,' ');window.lbOcrText=text;
 row(found.length?'ok':'warn',found.length?'Zaznaczone automatycznie: '+found.join(', ')+'. Sprawdź położenie i popraw, jeśli trzeba.':'Nie znalazłem napisów „Nr partii”, „Data produkcji” ani „Najlepiej do”. Zaznacz pola ręcznie.');
 var eans=[];(flat.match(/\d[\d ]{6,18}\d/g)||[]).forEach(function(m){var d=m.replace(/ /g,'');if(eanOk(d)&&eans.indexOf(d)<0)eans.push(d);});
 if(eans.length){var e=eans[0];
  if(K.variants>0)row('warn','Na etykiecie jest EAN '+e+', a produkt ma '+K.variants+' inne warianty. Etykieta wspólna dla wariantów nie może mieć EAN w pliku: usuń go z pliku i zaznacz pole „EAN”.');
  else if(K.ean&&K.ean!==e)row('warn','EAN na etykiecie ('+e+') różni się od EAN produktu ('+K.ean+').');
  else if(K.ean)row('ok','EAN '+e+' zgadza się z produktem.');
  else row('info','Na etykiecie jest EAN '+e+', a produkt nie ma EAN w karcie.');
  if(boxes.ean||boxes.ean_bar)row('warn','EAN jest w pliku i w zaznaczonym polu. Wydrukuje się dwa razy.');}
 else if(K.ean&&!boxes.ean&&!boxes.ean_bar)row('info','Nie znalazłem EAN na etykiecie (produkt ma '+K.ean+'). Jeśli ma być, zaznacz pole „EAN”.');
 var um=flat.match(/UFI\s*[:.]?\s*([A-Z0-9]{4})\s*-\s*([A-Z0-9]{4})\s*-\s*([A-Z0-9]{4})\s*-\s*([A-Z0-9]{4})/i),u=um?[um[1],um[2],um[3],um[4]].join('-').toUpperCase():'';
 if(u&&K.ufis.length){if(K.ufis.some(function(x){return ufiN(x)===ufiN(u);}))row('ok','UFI '+u+' zgadza się z zapachem w Zapachach.');else row('warn','UFI na etykiecie ('+u+') różni się od UFI zapachu ('+K.ufis.join(', ')+').');}
 else if(u)row('info','UFI z etykiety: '+u+'. Zapach tego produktu nie ma UFI w Zapachach, więc nie mam z czym porównać.');
 else if(K.ufis.length)row('warn','Nie znalazłem UFI na etykiecie, a zapach ma UFI '+K.ufis.join(', ')+'.');
 var ws=[],re=/(^|[^\d,.])(\d+(?:[.,]\d+)?)\s?(kg|g)\b/gi,m;while((m=re.exec(flat))){var g=parseFloat(m[2].replace(',','.'))*(m[3].toLowerCase()==='kg'?1000:1);if(g>0&&ws.indexOf(g)<0)ws.push(g);}
 if(K.weight){if(ws.some(function(g){return Math.abs(g-K.weight)<0.5;}))row('ok','Waga '+K.weight+' g zgadza się z kartą wyrobu.');
  else if(ws.length)row('warn','Waga na etykiecie ('+ws.join(' g, ')+' g) różni się od karty wyrobu ('+K.weight+' g).');else row('info','Nie znalazłem wagi na etykiecie (karta wyrobu: '+K.weight+' g).');}
 else if(ws.length)row('info','Waga na etykiecie: '+ws.join(' g, ')+' g. Karta wyrobu w Produkcji nie ma wagi do porównania.');
 if(lotInFile)row('warn','W pliku jest już wpisany numer partii ('+lotInFile+'). Usuń go z pliku, bo system wpisze numer w to samo miejsce.');
 if(chk.children.length<(fileNote?3:2))row('info','Nie znalazłem EAN, UFI ani wagi do sprawdzenia.');}
function ocr(){var i=img();if(!i){msg.textContent='Najpierw wybierz plik etykiety.';return;}var bt=document.getElementById('lb-ocr');bt.disabled=true;
 msg.style.color='';msg.textContent='Odczytuję etykietę… Za pierwszym razem przeglądarka pobiera ok. 7 MB, to może potrwać do minuty.';
 var sc=Math.min(3,2400/Math.max(i.naturalWidth,i.naturalHeight)),cv=document.createElement('canvas');cv.width=Math.round(i.naturalWidth*sc);cv.height=Math.round(i.naturalHeight*sc);
 var cx=cv.getContext('2d');cx.fillStyle='#fff';cx.fillRect(0,0,cv.width,cv.height);cx.imageSmoothingQuality='high';cx.drawImage(i,0,0,cv.width,cv.height);
 loadTess().then(function(w){return w.recognize(cv).then(function(res){msg.textContent='';info();return res;});}).then(function(res){var D=res.data,lines=D.lines||[],found=[];lotInFile='';
  ['lot','produced','expires','ean'].forEach(function(k){var f=findField(lines,FIND[k],cv.width,cv.height);if(!f)return;
   var b=f.box.map(function(v){return clamp(v,0,1);});b[2]=Math.min(b[2],1-b[0]);b[3]=Math.min(b[3],1-b[1]);
   if(k==='ean'){if(!f.value){boxes.ean=rnd(b);found.push(C.names.ean);}return;}
   if(k==='lot'&&f.value)lotInFile=f.value;boxes[k]=rnd(b);found.push(C.names[k]);});
  draw();check(D.text||'',found);
 }).catch(function(){msg.textContent='Nie udało się odczytać etykiety (brak połączenia albo stara przeglądarka). Zaznacz pola ręcznie.';msg.style.color='var(--danger-ink)';})
 .then(function(){bt.disabled=false;});}
document.getElementById('lb-ocr').onclick=ocr;
function loadPdfJs(){if(window.pdfjsLib)return Promise.resolve(window.pdfjsLib);
 var lib=new Promise(function(ok,no){var s=document.createElement('script');s.src=C.pdfjs+'pdf.min.js';s.integrity=C.sri.pdf;s.crossOrigin='anonymous';
  s.onload=function(){ok(window.pdfjsLib);};s.onerror=function(){no(new Error('pdf.js'));};document.head.appendChild(s);});
 var wk=fetch(C.pdfjs+'pdf.worker.min.js',{integrity:C.sri.worker,mode:'cors'}).then(function(r){if(!r.ok)throw new Error('worker');return r.blob();});
 return Promise.all([lib,wk]).then(function(a){a[0].GlobalWorkerOptions.workerSrc=URL.createObjectURL(new Blob([a[1]],{type:'text/javascript'}));return a[0];});}
file.addEventListener('change',function(){var f=file.files[0];if(!f)return;src.value=f.name;chk.hidden=true;fileNote='';
 if(f.type==='application/pdf'||/\.pdf$/i.test(f.name)){msg.textContent='Zamieniam PDF na obrazek do druku…';
  loadPdfJs().then(function(lib){return f.arrayBuffer().then(function(buf){return lib.getDocument({data:buf}).promise;});}).then(function(doc){return doc.getPage(1);}).then(function(pg){
   var v1=pg.getViewport({scale:1}),wm=v1.width*25.4/72,hm=v1.height*25.4/72,sc=Math.min(600/72,8000/Math.max(v1.width,v1.height)),v=pg.getViewport({scale:sc}),cv=document.createElement('canvas');
   cv.width=Math.round(v.width);cv.height=Math.round(v.height);var cx=cv.getContext('2d');cx.fillStyle='#fff';cx.fillRect(0,0,cv.width,cv.height);
   return pg.render({canvasContext:cx,viewport:v}).promise.then(function(){return new Promise(function(ok){cv.toBlob(function(b){ok([b,cv,wm,hm]);},'image/png');});});
  }).then(function(r){var png=new File([r[0]],f.name.replace(/\.pdf$/i,'')+'.png',{type:'image/png'}),dt=new DataTransfer();dt.items.add(png);file.files=dt.files;
   W.value=mmTxt(r[2]);H.value=mmTxt(r[3]);hAuto=false;setImg(URL.createObjectURL(png),r[1].width,r[1].height,ocr);
  }).catch(function(){file.value='';msg.textContent='Nie udało się otworzyć PDF. Zapisz etykietę jako PNG i wgraj ją jeszcze raz.';msg.style.color='var(--danger-ink)';});
  return;}
 if(!hAuto){H.value='';hAuto=true;}var u=URL.createObjectURL(f),t=new Image();t.onload=function(){
  fileMm(f,t.naturalWidth,t.naturalHeight).then(function(m){if(m){W.value=mmTxt(m.w);H.value='';hAuto=true;}
   setImg(u,t.naturalWidth,t.naturalHeight,function(){if(m){fileNote='Rozmiar z pliku: '+mmTxt(m.w)+' × '+mmTxt(m.h)+' mm ('+m.dpi+' dpi).';chk.innerHTML='';chk.hidden=false;row('info',fileNote);}
    else if(!(wmm()>0)){msg.textContent='Plik nie ma zapisanego rozmiaru. Wpisz szerokość etykiety w mm.';msg.style.color='var(--warn-ink)';}ocr();});});};t.src=u;});
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
            $html .= '<form class="card" method="get" action="ext.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-bottom:14px">'
                . '<input type="hidden" name="page" value="produkcja.etykieta_edytuj"><div style="width:min(380px,100%)">'
                . U::productPicker('sku', '', 'produkt', null, 'Dodaj etykietę do produktu', 'required placeholder="wpisz nazwę albo SKU"', false)
                . '</div><button class="btn">Dalej</button></form>';
        }
        if ($rows === []) {
            return $html . U::empty('Nie ma jeszcze etykiet', 'Wybierz produkt powyżej albo otwórz go w Magazynie i w sekcji „Etykieta CLP” wgraj plik.');
        }
        // Wyszukiwarka etykiet: filtruje listę na żywo po nazwie, SKU, wariancie i gramaturze.
        $html .= '<div class="pr-filter"><input type="search" id="lb-find" placeholder="Szukaj etykiety: nazwa, SKU, pojemność, gramatura" autocomplete="off" aria-label="Szukaj etykiety">'
            . '<span class="pr-small" id="lb-find-n">' . count($rows) . ' etykiet</span></div>';
        $html .= '<div class="card pr-table" style="padding:6px 10px"><table id="lb-rows"><thead><tr><th></th><th>Produkt</th><th class="pr-hide-m">SKU</th><th>Rozmiar</th><th class="pr-hide-m">Pola</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $f = array_values(array_intersect_key(Store::LABEL_FIELDS, $r['boxes']));
            $dpi = self::dpi($r);
            $meta = implode(' · ', array_filter([Store::variantText($r), $s->weightText($r)]));
            $q = mb_strtolower(implode(' ', [(string) $r['name'], (string) $r['sku'], $meta]));
            $html .= '<tr data-q="' . U::h($q) . '"><td><img class="lb-mini" src="' . U::h(self::fileUrl($r)) . '" alt="" loading="lazy"></td>'
                . '<td><a href="product_view.php?id=' . (int) $r['product_id'] . '"><b>' . U::h($r['name']) . '</b></a>' . ($meta !== '' ? ' <span class="pr-small">' . U::h($meta) . '</span>' : '') . ($dpi < 200 ? ' <span class="pill bad" title="Wydruk może być nieostry">' . $dpi . ' dpi</span>' : '') . '</td>'
                . '<td class="pr-hide-m">' . U::h($r['sku']) . '</td><td class="pr-num">' . U::n($r['width_mm']) . '×' . U::n($r['height_mm']) . ' mm</td>'
                . '<td class="pr-hide-m pr-small">' . U::h(mb_strtolower(implode(', ', $f))) . '</td>'
                . '<td>' . ($ctx['canEdit'] ? '<a href="' . U::h(U::url('etykieta_edytuj', ['produkt' => $r['product_id']])) . '">Edytuj</a> · ' : '')
                . '<a target="_blank" rel="noopener" href="' . U::h(U::url('etykieta_produkt', ['produkt' => $r['product_id']])) . '">Drukuj</a></td></tr>';
        }
        return $html . '<tr id="lb-none" hidden><td colspan="6" class="pr-muted">Żadna etykieta nie pasuje. Nowy produkt dodasz polem „Dodaj etykietę do produktu” wyżej.</td></tr></tbody></table></div>'
            . '<script>(function(){var f=document.getElementById("lb-find"),n=document.getElementById("lb-find-n"),none=document.getElementById("lb-none");if(!f)return;'
            . 'var rows=[].slice.call(document.querySelectorAll("#lb-rows tr[data-q]"));function go(){var w=f.value.toLowerCase().trim().split(/\\s+/).filter(Boolean),k=0;'
            . 'rows.forEach(function(r){var ok=w.every(function(x){return r.dataset.q.indexOf(x)>=0;});r.hidden=!ok;if(ok)k++;});n.textContent=k+" z "+rows.length;none.hidden=k>0;if(!w.length)n.textContent=rows.length+" etykiet";}'
            . 'f.addEventListener("input",go);})();</script>';
    }
}
