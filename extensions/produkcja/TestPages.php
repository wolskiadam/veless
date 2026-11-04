<?php
declare(strict_types=1);

namespace PaseExt\Produkcja;

use PaseExt\Produkcja\Ui as U;

/** Testy spalania: wyrób (i partia), knot, pomiary co godzinę, wynik, zdjęcie. */
final class TestPages
{
    private const PHOTO_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public static function dir(): string
    {
        return (defined('PASE_ROOT') ? PASE_ROOT : dirname(__DIR__, 2)) . '/storage/uploads/produkcja';
    }

    public static function list(Store $s, array $ctx): string
    {
        $rows = $s->tests();
        $html = U::style() . U::tabs('testy') . '<div class="pr-top"><p>Jak pali się świeca: jeziorko, płomień, kopcenie, tunelowanie.</p><div class="pr-actions">'
            . ($ctx['canEdit'] ? '<a class="btn" href="' . U::h(U::url('test')) . '">+ Nowy test</a>' : '') . '</div></div>';
        if ($rows === []) {
            return $html . U::empty('Jeszcze nie ma testów', 'Zapisuj testy spalania nowych wyrobów i knotów: pomiary co godzinę, wynik i zdjęcie. Przydadzą się do dokumentacji bezpieczeństwa (GPSR) i przy reklamacjach.',
                $ctx['canEdit'] ? '<a class="btn" href="' . U::h(U::url('test')) . '">+ Nowy test</a>' : '');
        }
        return $html . '<div class="card pr-table" style="padding:6px 10px">' . self::table($rows) . '</div>';
    }

    public static function table(array $rows): string
    {
        $html = '<table><thead><tr><th>Data</th><th>Wyrób</th><th class="pr-hide-m">Partia</th><th class="pr-hide-m">Knot</th><th class="pr-num">Godzin</th><th>Wynik</th></tr></thead><tbody>';
        foreach ($rows as $t) {
            $m = json_decode((string) $t['measurements'], true) ?: [];
            $hours = $m !== [] ? max(array_map(static fn(array $r): float => (float) ($r['h'] ?? 0), $m)) : 0;
            $cls = ['ok' => 'ok', 'fix' => 'warn', 'fail' => 'bad'][$t['result']] ?? 'muted';
            $html .= '<tr><td class="pr-num"><a href="' . U::h(U::url('test', ['id' => $t['id']])) . '">' . U::date($t['tested_at']) . '</a></td>'
                . '<td>' . U::h($t['product_name'] ?? '-') . '</td><td class="pr-hide-m pr-code">' . U::h($t['batch_code'] ?? '') . '</td>'
                . '<td class="pr-hide-m">' . U::h($t['wick']) . '</td><td class="pr-num">' . ($hours > 0 ? U::n($hours) : '-') . '</td>'
                . '<td><span class="pill ' . $cls . '">' . U::h(Store::RESULTS[$t['result']] ?? $t['result']) . '</span></td></tr>';
        }
        return $html . '</tbody></table>';
    }

    public static function form(Store $s, array $ctx, ?array $t, ?array $posted = null): string
    {
        $edit = $t !== null;
        $v = static fn(string $k, string $d = ''): string => (string) ($posted[$k] ?? $t[$k] ?? $d);
        $prods = ['' => '-'];
        foreach ($s->products() as $w) {
            $prods[$w['id']] = $w['name'];
        }
        $pid = $v('product_id', (string) ($_GET['wyrob'] ?? ''));
        $batches = ['' => '- bez partii -'];
        foreach ($s->batches(['draft' => false], 100) as $b) {
            $batches[$b['id']] = $b['code'] . ' · ' . $b['product_name'] . ' · ' . U::date($b['produced_at']);
        }
        $rows = $posted !== null ? array_values((array) ($posted['m'] ?? [])) : ($edit ? (json_decode((string) $t['measurements'], true) ?: []) : []);
        if ($rows === []) {
            $rows = [['h' => '1'], ['h' => '2'], ['h' => '3'], ['h' => '4']];
        }
        $row = static fn(int $i, array $r): string => '<tr>'
            . '<td><input name="m[' . $i . '][h]" value="' . U::h($r['h'] ?? '') . '" inputmode="decimal" style="width:70px"></td>'
            . '<td><input name="m[' . $i . '][pool]" value="' . U::h($r['pool'] ?? '') . '" inputmode="decimal" placeholder="mm"></td>'
            . '<td><input name="m[' . $i . '][depth]" value="' . U::h($r['depth'] ?? '') . '" inputmode="decimal" placeholder="mm"></td>'
            . '<td><input name="m[' . $i . '][flame]" value="' . U::h($r['flame'] ?? '') . '" inputmode="decimal" placeholder="mm"></td>'
            . '<td style="text-align:center"><input type="checkbox" name="m[' . $i . '][soot]" value="1"' . (!empty($r['soot']) ? ' checked' : '') . '></td>'
            . '<td style="text-align:center"><input type="checkbox" name="m[' . $i . '][tunnel]" value="1"' . (!empty($r['tunnel']) ? ' checked' : '') . '></td>'
            . '<td><input name="m[' . $i . '][note]" value="' . U::h($r['note'] ?? '') . '" maxlength="200"></td>'
            . '<td><button type="button" class="pr-del" onclick="this.closest(\'tr\').remove()">×</button></td></tr>';
        $body = '';
        foreach ($rows as $i => $r) {
            $body .= $row((int) $i, (array) $r);
        }
        $ro = !$ctx['canEdit'];
        return U::style() . U::tabs('testy') . U::back(U::url('testy'), 'Testy spalania')
            . '<form class="card pr-form" method="post" enctype="multipart/form-data" action="' . U::h(U::url('test')) . '"><h3 style="margin:0">' . ($edit ? 'Test spalania' : 'Nowy test spalania') . '</h3>'
            . U::csrf($ctx) . ($edit ? '<input type="hidden" name="id" value="' . U::h($t['id']) . '">' : '')
            . '<div class="pr-grid">'
            . U::select('product_id', $prods, $pid, 'Wyrób')
            . U::select('batch_id', $batches, $v('batch_id'), 'Partia (opcjonalnie)')
            . U::input('tested_at', $v('tested_at', date('Y-m-d')), 'Data testu', 'type="date"')
            . U::input('wick', $v('wick'), 'Knot', 'maxlength="200"', 'np. ECO 6, 2 szt.')
            . U::select('result', ['' => 'W trakcie', 'ok' => 'Zaliczony', 'fix' => 'Do poprawy', 'fail' => 'Odrzucony'], $v('result'), 'Wynik')
            . '</div><div class="pr-table"><b>Pomiary</b><table class="pr-recipe" style="margin-top:6px"><thead><tr><th>Godz.</th><th>Średnica jeziorka</th><th>Głębokość jeziorka</th><th>Płomień</th><th>Kopci</th><th>Tunel</th><th>Uwagi</th><th></th></tr></thead>'
            . '<tbody id="pr-m">' . $body . '</tbody></table><button type="button" class="btn secondary" style="margin-top:8px" id="pr-m-add">+ Pomiar</button>'
            . '<template id="pr-m-row">' . $row(999, []) . '</template></div>'
            . '<label>Wnioski<textarea name="notes" rows="3" maxlength="3000">' . U::h($v('notes')) . '</textarea></label>'
            . ($edit && $t['photo'] !== '' ? '<a href="' . U::h(U::url('test_zdjecie', ['id' => $t['id']])) . '" target="_blank" rel="noopener"><img class="pr-photo" src="' . U::h(U::url('test_zdjecie', ['id' => $t['id']])) . '" alt="Zdjęcie z testu"></a>' : '')
            . (!$ro ? '<label>' . ($edit && $t['photo'] !== '' ? 'Nowe zdjęcie (zastąpi obecne)' : 'Zdjęcie (opcjonalnie)') . '<input type="file" name="photo" accept="image/jpeg,image/png,image/webp" capture="environment"></label>'
                . '<div class="pr-actions"><button class="btn" type="submit">Zapisz test</button>'
                . ($edit ? '<button class="btn danger" type="submit" name="delete" value="1" onclick="return confirm(\'Usunąć test?\')">Usuń</button>' : '') . '</div>' : '')
            . '</form><script>(function(){var n=1000;var a=document.getElementById("pr-m-add");if(a)a.addEventListener("click",function(){document.getElementById("pr-m").insertAdjacentHTML("beforeend",document.getElementById("pr-m-row").innerHTML.replace(/\[999\]/g,"["+(n++)+"]"));});})();</script>';
    }

    public static function save(Store $s, ?array $t, array $in, ?array $file, string $user): array
    {
        $m = [];
        foreach ((array) ($in['m'] ?? []) as $r) {
            $row = ['h' => Store::num($r['h'] ?? ''), 'pool' => Store::num($r['pool'] ?? ''), 'depth' => Store::num($r['depth'] ?? ''),
                'flame' => Store::num($r['flame'] ?? ''), 'soot' => !empty($r['soot']), 'tunnel' => !empty($r['tunnel']), 'note' => mb_substr(trim((string) ($r['note'] ?? '')), 0, 200)];
            if ($row['pool'] !== null || $row['depth'] !== null || $row['flame'] !== null || $row['soot'] || $row['tunnel'] || $row['note'] !== '') {
                $m[] = $row;
            }
        }
        $pid = (string) ($in['product_id'] ?? '');
        $bid = (string) ($in['batch_id'] ?? '');
        $batch = $bid !== '' ? $s->batch($bid) : null;
        if ($pid === '' && $batch !== null) {
            $pid = (string) $batch['product_id'];
        }
        if ($pid === '' && $batch === null) {
            throw new \RuntimeException('Wybierz wyrób albo partię.');
        }
        $row = [
            'product_id' => $pid !== '' && $s->product($pid) !== null ? $pid : null,
            'batch_id' => $batch['id'] ?? null,
            'tested_at' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($in['tested_at'] ?? '')) ? (string) $in['tested_at'] : date('Y-m-d'),
            'wick' => mb_substr(trim((string) ($in['wick'] ?? '')), 0, 200),
            'result' => isset(Store::RESULTS[(string) ($in['result'] ?? '')]) ? (string) $in['result'] : '',
            'measurements' => json_encode($m, JSON_UNESCAPED_UNICODE),
            'notes' => mb_substr(trim((string) ($in['notes'] ?? '')), 0, 3000),
            'updated_at' => Store::now(),
        ];
        $photo = self::storePhoto($file);
        if ($photo !== null) {
            $row['photo'] = $photo[0];
            $row['photo_mime'] = $photo[1];
            if ($t !== null && $t['photo'] !== '' && is_file(self::dir() . '/' . $t['photo'])) {
                unlink(self::dir() . '/' . $t['photo']);
            }
        }
        if ($t === null) {
            $id = Store::id();
            $s->insert('ext_prod_tests', ['id' => $id, 'created_by' => $user, 'created_at' => Store::now()] + $row + ['photo' => '', 'photo_mime' => '']);
        } else {
            $id = (string) $t['id'];
            $s->update('ext_prod_tests', $id, $row);
        }
        return $s->test($id) ?? [];
    }

    public static function delete(Store $s, array $t): void
    {
        if ($t['photo'] !== '' && is_file(self::dir() . '/' . $t['photo'])) {
            unlink(self::dir() . '/' . $t['photo']);
        }
        $s->run('DELETE FROM ext_prod_tests WHERE id = ?', [$t['id']]);
    }

    /** @return array{0:string,1:string}|null [nazwa pliku, typ] */
    private static function storePhoto(?array $f): ?array
    {
        if ($f === null || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $tmp = (string) ($f['tmp_name'] ?? '');
        if (($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
            throw new \RuntimeException('Nie udało się wgrać zdjęcia.');
        }
        if (filesize($tmp) > 15 * 1024 * 1024) {
            throw new \RuntimeException('Zdjęcie jest za duże (najwyżej 15 MB).');
        }
        $info = getimagesize($tmp);
        $mime = is_array($info) ? (string) $info['mime'] : '';
        if (!isset(self::PHOTO_TYPES[$mime])) {
            throw new \RuntimeException('To nie jest zdjęcie JPG, PNG ani WebP.');
        }
        $dir = self::dir();
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Nie da się utworzyć katalogu na zdjęcia.');
        }
        $name = bin2hex(random_bytes(12)) . '.' . self::PHOTO_TYPES[$mime];
        if (!move_uploaded_file($tmp, $dir . '/' . $name)) {
            throw new \RuntimeException('Nie udało się zapisać zdjęcia.');
        }
        return [$name, $mime];
    }
}
