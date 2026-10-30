<?php
declare(strict_types=1);

namespace PaseExt\Paragony;

use Pase\Plugin\AbstractExtension;
use Pase\Plugin\ExtensionManifest;
use Pase\Plugin\Hooks;
use PDO;

/**
 * Paragony: zdjęcia paragonów z NIP-em (faktur uproszczonych) z zakupów firmy, z datą, sprzedawcą, kwotą i opisem.
 *
 *  - Zamówienia → Paragony: lista z wybranego roku, pogrupowana po miesiącach, z sumą każdego miesiąca i wyszukiwaniem,
 *  - dodawanie z telefonu (zdjęcie z aparatu, zmniejszane w przeglądarce przed wysłaniem) i z komputera (zdjęcie albo PDF),
 *  - podgląd paragonu, poprawianie i usuwanie,
 *  - „Pobierz miesiąc (ZIP)”: zdjęcia z miesiąca i zestawienie CSV, np. dla księgowej.
 *
 * Bez kont i połączeń z zewnątrz. Opis: README.md w tym katalogu.
 */
final class ParagonyExtension extends AbstractExtension
{
    private const MONTHS = ['styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];

    public function manifest(): ExtensionManifest
    {
        return new ExtensionManifest(
            type: 'paragony',
            name: 'Paragony',
            version: '1.0.0',
            author: 'Veless',
            description: 'Zdjęcia paragonów z NIP-em (faktur uproszczonych) z zakupów firmy: data, sprzedawca, kwota i co kupiono. Lista miesiącami z sumami, dodawanie zdjęcia prosto z telefonu i paczka ZIP z miesiąca dla księgowości.',
            icon: '🧾',
            fields: [
                ['key' => 'nip', 'label' => 'NIP firmy (przypomnienie przy dodawaniu paragonu)', 'type' => 'text',
                 'help' => 'Pokazuje się nad formularzem, żeby mieć go pod ręką przy kasie.'],
            ],
        );
    }

    public function boot(): void
    {
        $repo = fn(): Receipts => new Receipts($this->pdo());

        Hooks::addPage('lista', ['title' => '🧾 Paragony', 'group' => 'orders', 'role' => 'viewer',
            'render' => static fn(PDO $pdo, array $ctx): string => self::listPage($repo(), $ctx)]);

        Hooks::addPage('dodaj', ['title' => '🧾 Paragon', 'menu' => false, 'role' => 'editor',
            'render' => function (PDO $pdo, array $ctx) use ($repo): string {
                $r = $repo();
                $id = (string) ($_GET['id'] ?? $_POST['id'] ?? '');
                $row = $id !== '' ? $r->get($id) : null;
                if ($id !== '' && $row === null) {
                    return '<div class="flash err">Nie ma takiego paragonu.</div>';
                }
                if ($ctx['post']) {
                    try {
                        $saved = $r->save($row !== null ? $id : null, $_POST, $_FILES['photo'] ?? null, (string) $ctx['user']);
                        flash(($row !== null ? 'Zapisano zmiany paragonu: ' : 'Dodano paragon: ') . $saved['seller'] . ', ' . self::money($saved['gross']) . '.');
                        header('Location: ' . self::monthUrl((string) $saved['purchase_date'], (string) $saved['id']));
                        exit;
                    } catch (\RuntimeException $e) {
                        flash($e->getMessage() . (isset($_FILES['photo']) && ($_FILES['photo']['error'] ?? 4) !== UPLOAD_ERR_NO_FILE ? ' Dodaj zdjęcie jeszcze raz.' : ''), 'err');
                        return self::formPage($r, $ctx, $row, $_POST, (string) $this->setting('nip', ''));
                    }
                }
                return self::formPage($r, $ctx, $row, null, (string) $this->setting('nip', ''));
            }]);

        Hooks::addPage('zobacz', ['title' => '🧾 Paragon', 'menu' => false, 'role' => 'viewer',
            'render' => static function (PDO $pdo, array $ctx) use ($repo): string {
                $row = $repo()->get((string) ($_GET['id'] ?? ''));
                return $row === null ? '<div class="flash err">Nie ma takiego paragonu.</div>' : self::viewPage($row, $ctx);
            }]);

        Hooks::addPage('usun', ['title' => 'Usuń paragon', 'menu' => false, 'role' => 'editor',
            'render' => static function (PDO $pdo, array $ctx) use ($repo): void {
                $r = $repo();
                $row = $r->get((string) ($_POST['id'] ?? ''));
                if ($ctx['post'] && $row !== null) {
                    $r->delete((string) $row['id']);
                    flash('Usunięto paragon: ' . $row['seller'] . ', ' . self::money($row['gross']) . '.');
                }
                header('Location: ' . ($row !== null ? self::monthUrl((string) $row['purchase_date']) : Hooks::pageUrl('paragony.lista')));
                exit;
            }]);

        // Zdjęcie paragonu: tylko po zalogowaniu, z katalogu poza WWW.
        Hooks::addPage('plik', ['title' => 'Zdjęcie paragonu', 'menu' => false, 'role' => 'viewer', 'raw' => true,
            'render' => static function (PDO $pdo) use ($repo): void {
                $row = $repo()->get((string) ($_GET['id'] ?? ''));
                $path = $row !== null ? Receipts::filePath($row) : null;
                if ($path === null) {
                    http_response_code(404);
                    echo 'Nie ma takiego pliku.';
                    return;
                }
                $name = self::fileName($row, pathinfo($path, PATHINFO_EXTENSION));
                header('Content-Type: ' . $row['mime']);
                header('Content-Length: ' . filesize($path));
                header('Content-Disposition: ' . (isset($_GET['pobierz']) ? 'attachment' : 'inline') . '; filename="' . self::ascii($name) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
                header('X-Content-Type-Options: nosniff');
                header('Cache-Control: private, max-age=86400');
                readfile($path);
            }]);

        Hooks::addPage('zip', ['title' => 'Paragony z miesiąca', 'menu' => false, 'role' => 'viewer', 'raw' => true,
            'render' => static function (PDO $pdo) use ($repo): void {
                $month = (string) ($_GET['m'] ?? '');
                if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
                    http_response_code(400);
                    echo 'Zły miesiąc.';
                    return;
                }
                self::sendZip($repo()->list($month), $month);
            }]);
    }

    // ---------------------------------------------------------------- lista

    private static function listPage(Receipts $r, array $ctx): string
    {
        $years = $r->years();
        $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $year = (string) ($_GET['y'] ?? '');
        if (!preg_match('/^\d{4}$/', $year)) {
            $year = $years[0] ?? date('Y');
        }
        $rows = $r->list($year, $q);
        $byMonth = [];
        foreach ($rows as $row) {
            $byMonth[substr((string) $row['purchase_date'], 0, 7)][] = $row;
        }

        $opts = '';
        $all = array_unique(array_merge([date('Y'), $year], $years));
        rsort($all);
        foreach ($all as $y) {
            $opts .= '<option' . ($y === $year ? ' selected' : '') . '>' . self::h($y) . '</option>';
        }
        $html = self::style() . '<div class="card"><div class="pg-top">'
            . ($ctx['canEdit'] ? '<a class="btn" href="' . self::h(Hooks::pageUrl('paragony.dodaj')) . '">+ Dodaj paragon</a>' : '')
            . '<form method="get" action="ext.php" class="pg-filter"><input type="hidden" name="page" value="paragony.lista">'
            . '<label>Rok<br><select name="y" onchange="this.form.submit()">' . $opts . '</select></label>'
            . '<label>Szukaj<br><input type="search" name="q" value="' . self::h($q) . '" placeholder="sklep, NIP, co kupiono"></label>'
            . '<button class="btn secondary" type="submit">Pokaż</button>'
            . ($q !== '' ? '<a href="' . self::h(Hooks::pageUrl('paragony.lista', ['y' => $year])) . '">wyczyść</a>' : '') . '</form></div>';
        if ($rows !== []) {
            $html .= '<p class="pg-sum">' . self::h($year) . ($q !== '' ? ', szukane „' . self::h($q) . '”' : '') . ': <b>' . self::count(count($rows)) . '</b> na <b>'
                . self::money(array_sum(array_column($rows, 'gross'))) . '</b>' . self::vatNote($rows) . '</p>';
        }
        $html .= '</div>';

        if ($rows === []) {
            return $html . '<div class="card"><p style="margin:0;color:var(--ink-2)">' . ($q !== '' ? 'Brak paragonów dla tego wyszukiwania w ' . self::h($year) . ' roku.'
                : 'Brak paragonów z ' . self::h($year) . ' roku.' . ($ctx['canEdit'] ? ' Zrób zdjęcie paragonu i kliknij „+ Dodaj paragon”.' : '')) . '</p></div>';
        }
        foreach ($byMonth as $month => $list) {
            $html .= '<div class="card" id="m-' . self::h($month) . '"><div class="pg-mhead"><h3>' . self::h(ucfirst(self::monthName($month))) . '</h3>'
                . '<span>' . self::count(count($list)) . ' · <b>' . self::money(array_sum(array_column($list, 'gross'))) . '</b>' . self::vatNote($list) . '</span>'
                . ($q === '' ? '<a class="btn secondary pg-zip" href="' . self::h(Hooks::pageUrl('paragony.zip', ['m' => $month])) . '" title="Zdjęcia i zestawienie CSV">⬇ Pobierz miesiąc (ZIP)</a>' : '')
                . '</div><div class="pg-list">';
            foreach ($list as $row) {
                $html .= self::row($row);
            }
            $html .= '</div></div>';
        }
        return $html;
    }

    private static function row(array $row): string
    {
        $view = Hooks::pageUrl('paragony.zobacz', ['id' => $row['id']]);
        $pay = Receipts::PAYMENTS[(string) $row['payment']] ?? '';
        return '<a class="pg-row" id="p-' . self::h($row['id']) . '" href="' . self::h($view) . '">' . self::thumb($row)
            . '<span class="pg-main"><b>' . self::h($row['seller']) . '</b>'
            . ($row['seller_nip'] !== '' ? ' <small>NIP ' . self::h($row['seller_nip']) . '</small>' : '')
            . ($row['description'] !== '' ? '<span class="pg-desc">' . self::h($row['description']) . '</span>' : '') . '</span>'
            . '<span class="pg-amount"><b>' . self::money($row['gross']) . '</b><small>' . self::h(self::date((string) $row['purchase_date']))
            . ($row['payment'] !== '' ? '<span class="pg-pay"> · ' . self::h($pay) . '</span>' : '') . '</small></span></a>';
    }

    private static function thumb(array $row): string
    {
        if ($row['mime'] === 'application/pdf') {
            return '<span class="pg-thumb pg-pdf">PDF</span>';
        }
        return '<img class="pg-thumb" loading="lazy" alt="" src="' . self::h(Hooks::pageUrl('paragony.plik', ['id' => $row['id']])) . '">';
    }

    // ---------------------------------------------------------------- podgląd

    private static function viewPage(array $row, array $ctx): string
    {
        $file = Hooks::pageUrl('paragony.plik', ['id' => $row['id']]);
        $media = $row['mime'] === 'application/pdf'
            ? '<a class="btn secondary" href="' . self::h($file) . '" target="_blank" rel="noopener">Otwórz PDF</a>'
            : '<a href="' . self::h($file) . '" target="_blank" rel="noopener" title="Otwórz w pełnym rozmiarze"><img class="pg-photo" src="' . self::h($file) . '" alt="Zdjęcie paragonu"></a>';
        $line = static fn(string $label, string $value): string => $value === '' ? '' : '<dt>' . $label . '</dt><dd>' . $value . '</dd>';
        $html = self::style() . '<p><a href="' . self::h(self::monthUrl((string) $row['purchase_date'], (string) $row['id'])) . '">← Paragony: '
            . self::h(self::monthName(substr((string) $row['purchase_date'], 0, 7))) . '</a></p>'
            . '<div class="pg-view"><div class="card pg-media">' . $media . '</div><div class="card"><dl class="pg-dl">'
            . $line('Data zakupu', self::h(self::date((string) $row['purchase_date'])))
            . $line('Sprzedawca', '<b>' . self::h($row['seller']) . '</b>')
            . $line('NIP sprzedawcy', self::h($row['seller_nip']))
            . $line('Numer paragonu', self::h($row['receipt_no']))
            . $line('Kwota', '<b>' . self::money($row['gross']) . '</b>')
            . $line('w tym VAT', $row['vat'] !== null ? self::money($row['vat']) : '')
            . $line('Płatność', $row['payment'] !== '' ? self::h(Receipts::PAYMENTS[(string) $row['payment']] ?? '') : '')
            . $line('Co kupiono', '<span style="white-space:pre-wrap">' . self::h($row['description']) . '</span>')
            . $line('Dodał(a)', self::h(trim((string) $row['created_by'] . ', ' . self::when((string) $row['created_at']), ', ')))
            . '</dl><div class="pg-actions"><a class="btn secondary" href="' . self::h($file . '&pobierz=1') . '">⬇ Pobierz plik</a>';
        if ($ctx['canEdit']) {
            $html .= '<a class="btn secondary" href="' . self::h(Hooks::pageUrl('paragony.dodaj', ['id' => $row['id']])) . '">Popraw</a>'
                . '<form method="post" action="' . self::h(Hooks::pageUrl('paragony.usun')) . '" onsubmit="return confirm(\'Usunąć ten paragon razem ze zdjęciem?\')">'
                . '<input type="hidden" name="csrf" value="' . self::h($ctx['csrf']) . '"><input type="hidden" name="id" value="' . self::h($row['id']) . '">'
                . '<button class="btn secondary pg-del" type="submit">Usuń</button></form>';
        }
        return $html . '</div></div></div>';
    }

    // ---------------------------------------------------------------- formularz

    private static function formPage(Receipts $r, array $ctx, ?array $row, ?array $posted, string $nip = ''): string
    {
        $v = static fn(string $k, string $def = ''): string => self::h((string) ($posted[$k] ?? $row[$k] ?? $def));
        $gross = $posted['gross'] ?? ($row !== null ? number_format((float) $row['gross'], 2, ',', '') : '');
        $vat = $posted['vat'] ?? ($row !== null && $row['vat'] !== null ? number_format((float) $row['vat'], 2, ',', '') : '');
        $payment = (string) ($posted['payment'] ?? $row['payment'] ?? '');
        $pay = '';
        foreach (Receipts::PAYMENTS as $k => $label) {
            $pay .= '<option value="' . self::h($k) . '"' . ($k === $payment ? ' selected' : '') . '>' . self::h($label) . '</option>';
        }
        $sellers = $r->sellers();
        $list = '';
        foreach (array_keys($sellers) as $s) {
            $list .= '<option value="' . self::h($s) . '">';
        }
        $back = $row !== null ? Hooks::pageUrl('paragony.zobacz', ['id' => $row['id']]) : Hooks::pageUrl('paragony.lista');
        $current = $row !== null && $row['mime'] !== 'application/pdf' ? '<img class="pg-preview" id="pg-current" src="' . self::h(Hooks::pageUrl('paragony.plik', ['id' => $row['id']])) . '" alt="">' : '';

        return self::style() . '<p><a href="' . self::h($back) . '">← ' . ($row !== null ? 'Paragon' : 'Paragony') . '</a></p>'
            . '<form method="post" enctype="multipart/form-data" action="' . self::h(Hooks::pageUrl('paragony.dodaj')) . '" class="card pg-form" id="pg-form">'
            . '<h3 style="margin:0 0 4px">' . ($row !== null ? 'Popraw paragon' : 'Nowy paragon') . '</h3>'
            . ($nip !== '' ? '<p class="pg-nip">NIP firmy do podania przy kasie: <b>' . self::h($nip) . '</b></p>' : '')
            . '<input type="hidden" name="csrf" value="' . self::h($ctx['csrf']) . '">'
            . ($row !== null ? '<input type="hidden" name="id" value="' . self::h($row['id']) . '">' : '')
            . '<label class="pg-file">' . ($row !== null ? 'Nowe zdjęcie (zostaw puste, żeby zachować obecne)' : 'Zdjęcie paragonu') . '<br>'
            . '<input type="file" name="photo" id="pg-photo" accept="image/*,application/pdf"' . ($row === null ? ' required' : '') . '></label>'
            . '<div id="pg-prev">' . $current . '</div>'
            . '<div class="pg-grid">'
            . '<label>Data zakupu<br><input type="date" name="purchase_date" required max="' . date('Y-m-d') . '" value="' . $v('purchase_date', date('Y-m-d')) . '"></label>'
            . '<label>Kwota (brutto, zł)<br><input type="text" name="gross" required inputmode="decimal" placeholder="np. 123,45" value="' . self::h((string) $gross) . '"></label>'
            . '<label>Sprzedawca (sklep)<br><input type="text" name="seller" id="pg-seller" required maxlength="200" list="pg-sellers" autocomplete="off" value="' . $v('seller') . '"></label>'
            . '<label>NIP sprzedawcy<br><input type="text" name="seller_nip" id="pg-nip" maxlength="20" inputmode="numeric" placeholder="z paragonu" value="' . $v('seller_nip') . '"></label>'
            . '<label>w tym VAT (zł, opcjonalnie)<br><input type="text" name="vat" inputmode="decimal" value="' . self::h((string) $vat) . '"></label>'
            . '<label>Płatność<br><select name="payment">' . $pay . '</select></label>'
            . '<label>Numer paragonu (opcjonalnie)<br><input type="text" name="receipt_no" maxlength="64" value="' . $v('receipt_no') . '"></label>'
            . '</div>'
            . '<label>Co kupiono<br><textarea name="description" rows="3" maxlength="2000" placeholder="np. taśma pakowa i kartony do wysyłek">' . $v('description') . '</textarea></label>'
            . '<datalist id="pg-sellers">' . $list . '</datalist>'
            . '<div class="pg-actions"><button class="btn" type="submit" id="pg-save">Zapisz paragon</button><a class="btn secondary" href="' . self::h($back) . '">Anuluj</a></div>'
            . '</form>' . self::formScript($sellers);
    }

    /**
     * Podgląd wybranego zdjęcia, zmniejszenie dużego zdjęcia z aparatu przed wysłaniem (najdłuższy bok 2000 px, JPEG)
     * i NIP podpowiadany z poprzednich paragonów tego sprzedawcy. Bez JS formularz wysyła oryginał.
     */
    private static function formScript(array $sellers): string
    {
        $map = json_encode($sellers, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}';
        return <<<HTML
<script>
(function () {
  var sellers = {$map};
  var seller = document.getElementById('pg-seller'), nip = document.getElementById('pg-nip');
  seller.addEventListener('change', function () {
    if (nip.value === '' && sellers[seller.value]) { nip.value = sellers[seller.value]; }
  });
  var input = document.getElementById('pg-photo'), prev = document.getElementById('pg-prev'), save = document.getElementById('pg-save');
  var MAX = 2000;
  input.addEventListener('change', function () {
    var f = input.files && input.files[0];
    prev.innerHTML = '';
    if (!f) { return; }
    if (f.type === 'application/pdf') { prev.textContent = 'PDF: ' + f.name; return; }
    var img = document.createElement('img');
    img.className = 'pg-preview';
    img.src = URL.createObjectURL(f);
    prev.appendChild(img);
    if (!window.createImageBitmap || !window.DataTransfer || f.size < 600 * 1024) { return; }
    save.disabled = true;
    createImageBitmap(f).then(function (bmp) {
      var scale = Math.min(1, MAX / Math.max(bmp.width, bmp.height));
      var c = document.createElement('canvas');
      c.width = Math.round(bmp.width * scale); c.height = Math.round(bmp.height * scale);
      c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
      return new Promise(function (ok) { c.toBlob(ok, 'image/jpeg', 0.85); });
    }).then(function (blob) {
      if (!blob || blob.size >= f.size) { return; }
      var dt = new DataTransfer();
      dt.items.add(new File([blob], f.name.replace(/\.[^.]*$/, '') + '.jpg', { type: 'image/jpeg' }));
      input.files = dt.files;
    }).catch(function () {}).then(function () { save.disabled = false; });
  });
})();
</script>
HTML;
    }

    // ---------------------------------------------------------------- ZIP dla księgowości

    /** @param list<array<string,mixed>> $rows */
    private static function sendZip(array $rows, string $month): void
    {
        if (!class_exists(\ZipArchive::class)) {
            http_response_code(500);
            echo 'Serwer nie ma rozszerzenia PHP zip.';
            return;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'paragony');
        $zip = new \ZipArchive();
        if ($tmp === false || $zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            http_response_code(500);
            echo 'Nie udało się przygotować paczki.';
            return;
        }
        $csv = "\u{FEFF}" . self::csvLine(['Data zakupu', 'Sprzedawca', 'NIP sprzedawcy', 'Numer paragonu', 'Kwota brutto', 'VAT', 'Płatność', 'Co kupiono', 'Plik']);
        $used = [];
        foreach (array_reverse($rows) as $row) {
            $path = Receipts::filePath($row);
            $name = '';
            if ($path !== null) {
                $name = self::fileName($row, pathinfo($path, PATHINFO_EXTENSION));
                for ($i = 2; isset($used[$name]); ++$i) {
                    $name = preg_replace('/(\.\w+)$/', '_' . $i . '$1', self::fileName($row, pathinfo($path, PATHINFO_EXTENSION))) ?? $name;
                }
                $used[$name] = true;
                $zip->addFile($path, $name);
            }
            $csv .= self::csvLine([$row['purchase_date'], $row['seller'], $row['seller_nip'], $row['receipt_no'],
                number_format((float) $row['gross'], 2, ',', ''), $row['vat'] !== null ? number_format((float) $row['vat'], 2, ',', '') : '',
                Receipts::PAYMENTS[(string) $row['payment']] ?? '', $row['description'], $name]);
        }
        $csv .= self::csvLine(['Razem', '', '', '', number_format(array_sum(array_column($rows, 'gross')), 2, ',', ''), '', '', '', '']);
        $zip->addFromString('zestawienie-' . $month . '.csv', $csv);
        $zip->close();
        header('Content-Type: application/zip');
        header('Content-Length: ' . filesize($tmp));
        header('Content-Disposition: attachment; filename="paragony-' . $month . '.zip"');
        readfile($tmp);
        @unlink($tmp);
    }

    private static function csvLine(array $cells): string
    {
        return implode(';', array_map(static function ($c): string {
            $c = (string) $c;
            if ($c !== '' && str_contains('=+-@', $c[0]) && !is_numeric(str_replace(',', '.', $c))) {
                $c = "'" . $c; // arkusz nie potraktuje tekstu jako formuły
            }
            return '"' . str_replace('"', '""', $c) . '"';
        }, $cells)) . "\r\n";
    }

    /** Nazwa pliku dla księgowej: 2026-10-05_Castorama_123,45.jpg */
    private static function fileName(array $row, string $ext): string
    {
        $seller = trim(preg_replace('/[^\p{L}\p{N}]+/u', '-', (string) $row['seller']) ?? '', '-');
        return $row['purchase_date'] . '_' . mb_substr($seller !== '' ? $seller : 'paragon', 0, 40) . '_' . number_format((float) $row['gross'], 2, ',', '') . '.' . $ext;
    }

    // ---------------------------------------------------------------- pomocnicze

    private static function monthUrl(string $date, string $id = ''): string
    {
        return Hooks::pageUrl('paragony.lista', ['y' => substr($date, 0, 4)]) . '#' . ($id !== '' ? 'p-' . $id : 'm-' . substr($date, 0, 7));
    }

    private static function vatNote(array $rows): string
    {
        $vat = array_filter(array_column($rows, 'vat'), static fn($x): bool => $x !== null);
        return $vat === [] ? '' : ' <small>(w tym VAT ' . self::money(array_sum($vat)) . ')</small>';
    }

    private static function count(int $n): string
    {
        $word = $n === 1 ? 'paragon' : (in_array($n % 10, [2, 3, 4], true) && !in_array($n % 100, [12, 13, 14], true) ? 'paragony' : 'paragonów');
        return $n . ' ' . $word;
    }

    private static function money(mixed $v): string
    {
        return number_format((float) $v, 2, ',', "\u{00A0}") . "\u{00A0}zł";
    }

    private static function monthName(string $ym): string
    {
        [$y, $m] = array_map('intval', explode('-', $ym) + [1 => 0]);
        return (self::MONTHS[$m - 1] ?? $ym) . ' ' . $y;
    }

    private static function date(string $ymd): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m) ? $m[3] . '.' . $m[2] . '.' . $m[1] : $ymd;
    }

    private static function when(string $utc): string
    {
        $t = strtotime($utc . ' UTC');
        return $t === false ? $utc : date('d.m.Y H:i', $t);
    }

    private static function ascii(string $s): string
    {
        return preg_replace('/[^A-Za-z0-9.,_-]/', '_', strtr($s, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ż' => 'z', 'ź' => 'z',
            'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S', 'Ż' => 'Z', 'Ź' => 'Z'])) ?? 'paragon';
    }

    private static function h(mixed $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    private static function style(): string
    {
        return '<style>
.pg-top{display:flex;gap:12px;flex-wrap:wrap;align-items:end;justify-content:space-between}
.pg-filter{display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin:0}
.pg-sum{margin:12px 0 0;color:var(--ink-2)}
.pg-mhead{display:flex;gap:6px 14px;flex-wrap:wrap;align-items:baseline;margin:0 0 8px}
.pg-mhead h3{margin:0}
.pg-mhead .pg-zip{margin-left:auto;padding:4px 10px;font-size:13px}
.pg-list{display:flex;flex-direction:column}
.pg-row{display:flex;gap:12px;align-items:center;padding:8px 4px;border-top:1px solid var(--line,#e5e2da);color:inherit;text-decoration:none}
.pg-row:hover{background:var(--bg-2,rgba(0,0,0,.03))}
.pg-thumb{width:52px;height:52px;flex:none;object-fit:cover;border-radius:6px;border:1px solid var(--line,#e5e2da);background:#fff}
.pg-pdf{display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#b3261e}
.pg-main{flex:1;min-width:0}
.pg-main small,.pg-amount small{color:var(--ink-3)}
.pg-desc{display:block;color:var(--ink-2);font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pg-amount{text-align:right;white-space:nowrap;display:flex;flex-direction:column}
.pg-view{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1fr);gap:16px;align-items:start}
.pg-view .card{margin:0}
.pg-photo{display:block;max-width:100%;max-height:80vh;margin:0 auto;border-radius:6px}
.pg-dl{display:grid;grid-template-columns:max-content 1fr;gap:6px 14px;margin:0 0 14px}
.pg-dl dt{color:var(--ink-3)}
.pg-dl dd{margin:0}
.pg-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.pg-actions form{margin:0}
.pg-del{color:#b3261e}
.pg-form{display:flex;flex-direction:column;gap:12px;max-width:760px}
.pg-form input:not([type=file]),.pg-form select,.pg-form textarea{width:100%;box-sizing:border-box}
.pg-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.pg-nip{margin:0;color:var(--ink-2)}
.pg-file input{max-width:100%}
.pg-preview{display:block;max-width:100%;max-height:300px;border-radius:6px;border:1px solid var(--line,#e5e2da)}
@media (max-width:720px){.pg-view,.pg-grid{grid-template-columns:1fr}.pg-mhead .pg-zip{margin-left:0}.pg-main small{display:block}.pg-pay{display:none}.pg-thumb{width:44px;height:44px}}
</style>';
    }
}
