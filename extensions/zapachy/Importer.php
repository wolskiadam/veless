<?php
declare(strict_types=1);

namespace PaseExt\Zapachy;

/**
 * Import zapachów i nut z wtyczki WordPress „Aroma Helper” (aroma-category) i z Excela:
 *  - kopia bazy z phpMyAdmin (.sql): tabele wp_aroma_fragrances i wp_aroma_notes (dowolny prefiks),
 *  - CSV: eksport tych tabel z phpMyAdmin albo własny plik z Excela (kolumny po nagłówkach, po polsku albo jak w bazie),
 *  - strona katalogu ze sklepu (shortcode [aroma_zapachy]): numer, nazwa, nuty i opis z kart.
 *
 * Ponowny import niczego nie dubluje: zapach z bazy WordPress rozpoznajemy po jego id (source_id „wp:12”), inne po nazwie.
 * Puste pole w pliku nie kasuje tego, co już jest w CRM.
 */
final class Importer
{
    /** Nagłówek (po norm()) => pole. */
    private const COLUMNS = [
        'id' => 'wp_id',
        'storage number' => 'storage_no', 'storage no' => 'storage_no', 'numer' => 'storage_no', 'nr' => 'storage_no', 'miejsce' => 'storage_no',
        'numer miejsca' => 'storage_no', 'nr miejsca' => 'storage_no', 'numer w magazynie' => 'storage_no', 'nr magazynowy' => 'storage_no',
        'numer magazynowy' => 'storage_no', 'magazyn' => 'storage_no', 'numer zapachu' => 'storage_no', 'nr zapachu' => 'storage_no',
        'trade names' => 'trade_names', 'nazwa' => 'trade_names', 'zapach' => 'trade_names', 'name' => 'trade_names', 'nazwa zapachu' => 'trade_names',
        'nazwy handlowe' => 'trade_names', 'nazwa handlowa' => 'trade_names',
        'inne nazwy' => 'aliases', 'aliases' => 'aliases',
        'producer name' => 'producer', 'producent' => 'producer', 'producer' => 'producer', 'dostawca' => 'producer',
        'fragrance number' => 'producer_code', 'kod' => 'producer_code', 'kod producenta' => 'producer_code', 'numer producenta' => 'producer_code',
        'head notes' => 'head_notes', 'nuty glowy' => 'head_notes', 'glowa' => 'head_notes', 'nuta glowy' => 'head_notes',
        'heart notes' => 'heart_notes', 'nuty serca' => 'heart_notes', 'serce' => 'heart_notes', 'nuta serca' => 'heart_notes',
        'base notes' => 'base_notes', 'nuty bazy' => 'base_notes', 'baza' => 'base_notes', 'nuta bazy' => 'base_notes',
        'commercial description' => 'description', 'opis' => 'description', 'description' => 'description',
        'comments' => 'comments', 'uwagi' => 'comments', 'komentarz' => 'comments', 'notatki' => 'comments',
        'price per gram' => 'price_per_g', 'cena za gram' => 'price_per_g', 'cena g' => 'price_per_g', 'cena' => 'price_per_g',
        'max concentration' => 'max_concentration', 'maks stezenie' => 'max_concentration', 'max stezenie' => 'max_concentration',
        'ufi 6' => 'ufi_6', 'ufi 8' => 'ufi_8', 'ufi 10' => 'ufi_10', 'ufi' => 'ufi',
        // słownik nut
        'note name' => 'note_name', 'nuta' => 'note_name', 'category' => 'category', 'kategoria' => 'category',
    ];

    /** Kolumny tabeli wp_aroma_fragrances w kolejności z wtyczki (gdy INSERT nie ma listy kolumn). */
    private const WP_FRAGRANCE_COLUMNS = ['id', 'producer_name', 'fragrance_number', 'trade_names', 'head_notes', 'heart_notes', 'base_notes',
        'head_notes_en', 'heart_notes_en', 'base_notes_en', 'max_concentration', 'ufi_6', 'ufi_8', 'ufi_10', 'stock_amount', 'stock_unit',
        'price_per_gram', 'storage_number', 'impressions', 'commercial_description', 'comments', 'created_at', 'updated_at'];
    private const WP_NOTE_COLUMNS = ['id', 'note_name', 'category'];

    public function __construct(private readonly Store $s) {}

    /**
     * Plik z dysku: .sql (kopia bazy), .csv/.txt (Excel, phpMyAdmin), .html (zapisana strona katalogu).
     * @return array{added:int,updated:int,notes:int,skipped:int,warnings:list<string>}
     */
    public function file(string $content, string $filename): array
    {
        $content = self::utf8($content);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === 'sql' || preg_match('/INSERT\s+INTO\s+`?\w*aroma_(fragrances|notes)`?/i', $content)) {
            return $this->sql($content);
        }
        if (in_array($ext, ['html', 'htm'], true) || str_contains($content, 'aroma-card')) {
            return $this->html($content);
        }
        return $this->csv($content);
    }

    // ---------------------------------------------------------------- formaty

    /** @return array{added:int,updated:int,notes:int,skipped:int,warnings:list<string>} */
    public function csv(string $content): array
    {
        $content = self::utf8($content);
        $lines = preg_split('/\R/u', ltrim($content, "\xEF\xBB\xBF")) ?: [];
        $first = '';
        foreach ($lines as $l) {
            if (trim($l) !== '') {
                $first = $l;
                break;
            }
        }
        $sep = ';';
        $best = -1;
        foreach ([';', ',', "\t"] as $c) {
            $n = substr_count($first, $c);
            if ($n > $best) {
                [$sep, $best] = [$c, $n];
            }
        }
        $h = fopen('php://temp', 'r+');
        fwrite($h, ltrim($content, "\xEF\xBB\xBF"));
        rewind($h);
        $header = null;
        $rows = [];
        while (($r = fgetcsv($h, 0, $sep, '"', '\\')) !== false) {
            if ($r === [null] || implode('', array_map('trim', array_map('strval', $r))) === '') {
                continue;
            }
            if ($header === null) {
                $header = array_map(static fn($c) => self::COLUMNS[Store::norm((string) $c)] ?? Store::norm((string) $c), $r);
                continue;
            }
            $row = [];
            foreach ($header as $i => $key) {
                $row[$key] = trim((string) ($r[$i] ?? ''));
            }
            $rows[] = $row;
        }
        fclose($h);
        if ($header === null) {
            throw new \RuntimeException('Plik jest pusty.');
        }
        $isNotes = in_array('note_name', $header, true) && !in_array('trade_names', $header, true);
        if (!$isNotes && !in_array('trade_names', $header, true)) {
            throw new \RuntimeException('Nie znalazłem kolumny z nazwą zapachu. Pierwszy wiersz pliku to nagłówki, np. „numer;nazwa;nuty głowy;nuty serca;nuty bazy”.');
        }
        return $isNotes ? $this->importNotes($rows) : $this->importFragrances($rows);
    }

    /** @return array{added:int,updated:int,notes:int,skipped:int,warnings:list<string>} */
    public function sql(string $content): array
    {
        $tables = ['fragrances' => [], 'notes' => []];
        if (!preg_match_all('/INSERT\s+(?:IGNORE\s+)?INTO\s+`?(\w*?)aroma_(fragrances|notes)`?\s*(\(([^)]*)\))?\s*VALUES\s*/i', $content, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            throw new \RuntimeException('W pliku nie ma danych tabel aroma_fragrances ani aroma_notes. Wyeksportuj je w phpMyAdmin (Eksport → SQL).');
        }
        foreach ($m as $hit) {
            $kind = strtolower($hit[2][0]);
            $cols = isset($hit[4]) && $hit[4][0] !== ''
                ? array_map(static fn($c) => trim($c, " `\"\t\r\n"), explode(',', $hit[4][0]))
                : ($kind === 'fragrances' ? self::WP_FRAGRANCE_COLUMNS : self::WP_NOTE_COLUMNS);
            $start = $hit[0][1] + strlen($hit[0][0]);
            foreach (self::tuples($content, $start) as $vals) {
                $row = [];
                foreach ($cols as $i => $c) {
                    $row[$c] = $vals[$i] ?? '';
                }
                $tables[$kind][] = $row;
            }
        }
        $notes = $this->importNotes(array_map(static fn(array $r) => ['note_name' => (string) ($r['note_name'] ?? ''), 'category' => (string) ($r['category'] ?? '')], $tables['notes']));
        $map = [];
        foreach ($tables['fragrances'] as $r) {
            $map[] = ['wp_id' => (string) ($r['id'] ?? ''), 'storage_no' => (string) ($r['storage_number'] ?? ''), 'trade_names' => (string) ($r['trade_names'] ?? ''),
                'producer' => (string) ($r['producer_name'] ?? ''), 'producer_code' => (string) ($r['fragrance_number'] ?? ''),
                'head_notes' => (string) ($r['head_notes'] ?? ''), 'heart_notes' => (string) ($r['heart_notes'] ?? ''), 'base_notes' => (string) ($r['base_notes'] ?? ''),
                'description' => (string) ($r['commercial_description'] ?? ''), 'comments' => (string) ($r['comments'] ?? ''),
                'price_per_g' => (string) ($r['price_per_gram'] ?? ''), 'max_concentration' => (string) ($r['max_concentration'] ?? ''),
                'ufi_6' => (string) ($r['ufi_6'] ?? ''), 'ufi_8' => (string) ($r['ufi_8'] ?? ''), 'ufi_10' => (string) ($r['ufi_10'] ?? '')];
        }
        $res = $this->importFragrances($map);
        $res['notes'] += $notes['notes'];
        $res['warnings'] = array_merge($notes['warnings'], $res['warnings']);
        return $res;
    }

    /**
     * Strona katalogu ze sklepu (shortcode [aroma_zapachy]): karta .aroma-card ma numer (#18), nazwę, nuty Głowa/Serce/Baza i opis.
     * @return array{added:int,updated:int,notes:int,skipped:int,warnings:list<string>}
     */
    public function html(string $content): array
    {
        if (!str_contains($content, 'aroma-card')) {
            throw new \RuntimeException('Na tej stronie nie ma katalogu zapachów (szukam kart z wtyczki Aroma Helper, shortcode [aroma_zapachy]).');
        }
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $content, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $xp = new \DOMXPath($doc);
        $cls = static fn(string $c): string => "contains(concat(' ', normalize-space(@class), ' '), ' {$c} ')";
        $rows = [];
        foreach ($xp->query('//div[' . $cls('aroma-card') . ']') ?: [] as $card) {
            $get = static function (string $q) use ($xp, $card): string {
                $n = $xp->query($q, $card);
                return $n !== false && $n->length > 0 ? trim(preg_replace('/\s+/u', ' ', $n->item(0)->textContent) ?? '') : '';
            };
            $row = ['storage_no' => ltrim($get('.//*[' . $cls('aroma-storage-no') . ']'), '#'), 'trade_names' => $get('.//*[' . $cls('aroma-card-title') . ']'),
                'description' => '', 'head_notes' => '', 'heart_notes' => '', 'base_notes' => ''];
            foreach ($xp->query('.//*[' . $cls('aroma-notes-preview') . ']/div', $card) ?: [] as $div) {
                $t = trim(preg_replace('/\s+/u', ' ', $div->textContent) ?? '');
                if (preg_match('/^(G[łl]owa|Serce|Baza)\s*:\s*(.*)$/iu', $t, $mm)) {
                    $key = ['g' => 'head_notes', 's' => 'heart_notes', 'b' => 'base_notes'][mb_strtolower(mb_substr($mm[1], 0, 1))];
                    $row[$key] = $mm[2];
                }
            }
            $desc = $xp->query('.//*[' . $cls('aroma-description-content') . ']', $card);
            if ($desc !== false && $desc->length > 0) {
                $parts = [];
                foreach ($desc->item(0)->childNodes as $c) {
                    $t = trim($c->textContent);
                    if ($t !== '') {
                        $parts[] = $t;
                    }
                }
                $row['description'] = implode("\n\n", $parts);
            }
            if ($row['trade_names'] !== '') {
                $rows[] = $row;
            }
        }
        if ($rows === []) {
            throw new \RuntimeException('Nie znalazłem żadnej karty zapachu na tej stronie.');
        }
        return $this->importFragrances($rows);
    }

    /**
     * Pobiera stronę katalogu ze sklepu. Tylko http(s) i adresy w internecie (bez sieci lokalnej serwera).
     * @return array{added:int,updated:int,notes:int,skipped:int,warnings:list<string>}
     */
    public function url(string $url): array
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException('Podaj pełny adres strony, np. https://twojsklep.pl/zapachy/.');
        }
        if (isset($parts['port']) && !in_array((int) $parts['port'], [80, 443], true)) {
            throw new \RuntimeException('Adres strony nie może mieć własnego portu.');
        }
        $ips = gethostbynamel((string) $parts['host']) ?: [];
        if ($ips === []) {
            throw new \RuntimeException('Nie znalazłem takiego adresu: ' . $parts['host'] . '.');
        }
        foreach ($ips as $ip) {
            $flags = defined('FILTER_FLAG_GLOBAL_RANGE') ? FILTER_FLAG_GLOBAL_RANGE : (FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
                throw new \RuntimeException('Ten adres prowadzi do sieci wewnętrznej - podaj publiczny adres sklepu.');
            }
        }
        $r = \Pase\Support\Http::request('GET', $url, ['User-Agent' => 'Mozilla/5.0 (CRM; import zapachów)', 'Accept' => 'text/html'], null, 20);
        if (!$r->isSuccess()) {
            throw new \RuntimeException('Strona nie odpowiedziała poprawnie (kod ' . $r->status . ').');
        }
        if (strlen($r->body) > 10_000_000) {
            throw new \RuntimeException('Strona jest za duża.');
        }
        return $this->html($r->body);
    }

    // ---------------------------------------------------------------- zapis

    /**
     * @param list<array<string,string>> $rows pola jak w COLUMNS
     * @return array{added:int,updated:int,notes:int,skipped:int,warnings:list<string>}
     */
    public function importFragrances(array $rows): array
    {
        $res = ['added' => 0, 'updated' => 0, 'notes' => 0, 'skipped' => 0, 'warnings' => []];
        foreach ($rows as $i => $r) {
            $names = Store::names(['name' => '', 'aliases' => str_replace("\n", ',', (string) ($r['trade_names'] ?? ''))]);
            if (isset($r['aliases']) && $r['aliases'] !== '') {
                $names = array_values(array_unique(array_merge($names, Store::names(['name' => '', 'aliases' => $r['aliases']]))));
            }
            if ($names === []) {
                $res['skipped']++;
                continue;
            }
            $in = [
                'name' => $names[0],
                'aliases' => implode(', ', array_slice($names, 1)),
                'storage_no' => ltrim(trim((string) ($r['storage_no'] ?? '')), '#'),
                'producer' => (string) ($r['producer'] ?? ''),
                'producer_code' => (string) ($r['producer_code'] ?? ''),
                'head_notes' => (string) ($r['head_notes'] ?? ''),
                'heart_notes' => (string) ($r['heart_notes'] ?? ''),
                'base_notes' => (string) ($r['base_notes'] ?? ''),
                'description' => (string) ($r['description'] ?? ''),
                'comments' => (string) ($r['comments'] ?? ''),
                'price_per_g' => self::positive($r['price_per_g'] ?? ''),
                'max_concentration' => self::positive($r['max_concentration'] ?? ''),
                'ufi' => self::ufi($r),
            ];
            $sourceId = isset($r['wp_id']) && ctype_digit(trim((string) $r['wp_id'])) ? 'wp:' . trim((string) $r['wp_id']) : '';
            $old = $this->find($sourceId, $names);
            if ($in['storage_no'] !== '') {
                $taken = $this->s->one('SELECT id, name FROM ext_zap_fragrances WHERE LOWER(storage_no) = LOWER(?) AND archived = 0 AND id <> ?', [$in['storage_no'], (string) ($old['id'] ?? '')]);
                if ($taken !== null) {
                    $res['warnings'][] = '„' . $in['name'] . '”: numer ' . $in['storage_no'] . ' ma już „' . $taken['name'] . '” - zapisany bez numeru.';
                    $in['storage_no'] = '';
                }
            }
            if ($old !== null) {
                // Puste pole w pliku nie kasuje danych w CRM; inne nazwy dokładamy.
                $merged = $old;
                foreach ($in as $k => $v) {
                    if ($k === 'aliases') {
                        $merged['aliases'] = implode(', ', array_slice(array_values(array_unique(array_merge(
                            Store::names(['name' => '', 'aliases' => (string) $old['aliases']]), Store::names(['name' => '', 'aliases' => $v])))), 0, 30));
                    } elseif ($k !== 'name' && trim((string) $v) !== '') {
                        $merged[$k] = $v;
                    }
                }
                if ($sourceId !== '') {
                    $merged['source_id'] = $sourceId;
                }
                $changed = false;
                foreach (['storage_no', 'aliases', 'producer', 'producer_code', 'head_notes', 'heart_notes', 'base_notes', 'description', 'comments', 'price_per_g', 'max_concentration', 'ufi', 'source_id'] as $k) {
                    if (array_key_exists($k, $merged) && self::cmp($merged[$k]) !== self::cmp($old[$k] ?? '')) {
                        $changed = true;
                    }
                }
                if (!$changed) {
                    $res['skipped']++;
                    continue;
                }
                unset($merged['material_id']);
                $this->s->saveFragrance((string) $old['id'], $merged);
                $res['updated']++;
            } else {
                $this->s->saveFragrance(null, $in + ['source_id' => $sourceId]);
                $res['added']++;
            }
        }
        // Nuty z zapachów, których nie ma w słowniku, trafiają do niego (bez kategorii).
        $known = $this->s->noteCategories();
        foreach ($this->s->allFragrances() as $f) {
            foreach (Store::fragranceNotes($f) as $n) {
                if (!isset($known[$n]) && mb_strlen($n) <= 100) {
                    $this->s->saveNote($n, '');
                    $known[$n] = '';
                    $res['notes']++;
                }
            }
        }
        return $res;
    }

    /** @param list<array<string,string>> $rows @return array{added:int,updated:int,notes:int,skipped:int,warnings:list<string>} */
    public function importNotes(array $rows): array
    {
        $text = implode("\n", array_map(static fn(array $r) => str_replace([',', ';', "\n"], ' ', (string) ($r['note_name'] ?? '')) . ',' . str_replace("\n", ' ', (string) ($r['category'] ?? '')), $rows));
        $r = $this->s->bulkNotes($text);
        return ['added' => 0, 'updated' => 0, 'notes' => $r['added'], 'skipped' => $r['skipped'], 'warnings' => $r['errors']];
    }

    private function find(string $sourceId, array $names): ?array
    {
        if ($sourceId !== '') {
            $hit = $this->s->one('SELECT * FROM ext_zap_fragrances WHERE source_id = ?', [$sourceId]);
            if ($hit !== null) {
                return $hit;
            }
        }
        $want = array_map([Store::class, 'norm'], $names);
        foreach ($this->s->all('SELECT * FROM ext_zap_fragrances') as $f) {
            if (in_array(Store::norm((string) $f['name']), $want, true)) {
                return $f;
            }
        }
        return null;
    }

    // ---------------------------------------------------------------- pomocnicze

    private static function cmp(mixed $v): string
    {
        return is_numeric($v) ? rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.') : trim((string) $v);
    }

    private static function positive(mixed $v): string
    {
        $n = Store::num($v);
        return $n !== null && $n > 0 ? (string) $n : '';
    }

    private static function ufi(array $r): string
    {
        if (trim((string) ($r['ufi'] ?? '')) !== '') {
            return trim((string) $r['ufi']);
        }
        $out = [];
        foreach (['6', '8', '10'] as $p) {
            $v = trim((string) ($r['ufi_' . $p] ?? ''));
            if ($v !== '') {
                $out[] = $p . '%: ' . $v;
            }
        }
        return implode(', ', $out);
    }

    /** UTF-8 albo Windows-1250 (Excel) → UTF-8. */
    public static function utf8(string $s): string
    {
        if (mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        $conv = @iconv('Windows-1250', 'UTF-8//IGNORE', $s);
        return $conv !== false ? $conv : mb_convert_encoding($s, 'UTF-8', 'ISO-8859-2');
    }

    /**
     * Krotki VALUES z SQL od pozycji $pos do średnika: (1,'a\'b',NULL),(2,...). Rozumie cudzysłowy, ucieczki i NULL.
     * @return \Generator<list<string>>
     */
    private static function tuples(string $sql, int $pos): \Generator
    {
        $len = strlen($sql);
        while ($pos < $len) {
            while ($pos < $len && ctype_space($sql[$pos])) {
                $pos++;
            }
            if ($pos >= $len || $sql[$pos] === ';') {
                return;
            }
            if ($sql[$pos] === ',') {
                $pos++;
                continue;
            }
            if ($sql[$pos] !== '(') {
                return;
            }
            $pos++;
            $vals = [];
            $cur = '';
            $quoted = false;
            while ($pos < $len) {
                $c = $sql[$pos];
                if ($c === "'" || $c === '"') {
                    $q = $c;
                    $pos++;
                    $buf = '';
                    while ($pos < $len) {
                        $d = $sql[$pos];
                        if ($d === '\\' && $pos + 1 < $len) {
                            $n = $sql[$pos + 1];
                            $buf .= match ($n) { 'n' => "\n", 'r' => "\r", 't' => "\t", '0' => "\0", default => $n };
                            $pos += 2;
                            continue;
                        }
                        if ($d === $q) {
                            if ($pos + 1 < $len && $sql[$pos + 1] === $q) {
                                $buf .= $q;
                                $pos += 2;
                                continue;
                            }
                            $pos++;
                            break;
                        }
                        $buf .= $d;
                        $pos++;
                    }
                    $cur = $buf;
                    $quoted = true;
                    continue;
                }
                if ($c === ',' || $c === ')') {
                    $v = $quoted ? $cur : trim($cur);
                    $vals[] = (!$quoted && strtoupper($v) === 'NULL') ? '' : $v;
                    [$cur, $quoted] = ['', false];
                    $pos++;
                    if ($c === ')') {
                        break;
                    }
                    continue;
                }
                if (!$quoted) {
                    $cur .= $c;
                }
                $pos++;
            }
            yield $vals;
        }
    }
}
