<?php
declare(strict_types=1);

/**
 * Raport sandboxu wtyczek: łączy skan kodu z tym, co wtyczka zrobiła w przebiegu (run.sh).
 *
 *   php report.php <katalog-wyników> <slug> <extension|integration> <integrations|extensions>
 *
 * Porównuje przebieg bez wtyczki (base) z przebiegiem z wtyczką (plugin): pliki, zapytania do bazy,
 * połączenia sieciowe, procesy, błędy stron. Liczy się tylko to, czego nie było bez wtyczki.
 * Pisze raport.md i raport.json. Kod wyjścia: 0 - przeszła, 1 - odrzucona.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

[$dir, $slug, $kind, $dest] = [rtrim($argv[1] ?? '', '/'), $argv[2] ?? '', $argv[3] ?? 'integration', $argv[4] ?? 'integrations'];
$isExt = $kind === 'extension';

$read = static fn(string $f): string => is_file("$dir/$f") ? (string) file_get_contents("$dir/$f") : '';
$json = static fn(string $f): array => json_decode($read($f), true) ?: [];
$scan = $json('scan.json');
$canaries = $json('canaries.json');
$findings = [];   // [level, area, message, details]

function finding(string $level, string $area, string $message, string $details = ''): void
{
    global $findings;
    foreach ($findings as $f) {
        if ($f[0] === $level && $f[1] === $area && $f[2] === $message) {
            return;
        }
    }
    $findings[] = [$level, $area, $message, $details];
}

// ------------------------------------------------------------ 1. skan kodu

foreach ($scan['findings'] ?? [] as $f) {
    if ($f['level'] === 'info') {
        continue;
    }
    finding($f['level'], 'Kod', $f['message'], $f['file'] . ':' . implode(',', $f['lines']) . ($f['code'] !== '' ? ' `' . str_replace('`', "'", $f['code']) . '`' : ''));
}
$ownHosts = array_map('strtolower', $scan['hosts'] ?? []);
$ownHosts[] = 'sklep-testowy.example';   // adres sklepu wpisany w konto testowe

// ------------------------------------------------------------ 2. pliki

function snapshot(string $text): array
{
    $out = [];
    foreach (explode("\n", trim($text)) as $line) {
        if (preg_match('/^([0-9a-f]{64})\s+(.+)$/', $line, $m)) {
            $out[$m[2]] = $m[1];
        }
    }
    return $out;
}
function changes(array $before, array $after): array
{
    $c = [];
    foreach ($after as $p => $h) {
        if (!isset($before[$p])) {
            $c[$p] = 'nowy';
        } elseif ($before[$p] !== $h) {
            $c[$p] = 'zmieniony';
        }
    }
    foreach ($before as $p => $h) {
        if (!isset($after[$p])) {
            $c[$p] = 'usunięty';
        }
    }
    return $c;
}
$coreChanges = changes(snapshot($read('files-base-before.txt')), snapshot($read('files-base-after.txt')));
$pluginChanges = changes(snapshot($read('files-plugin-before.txt')), snapshot($read('files-plugin-after.txt')));
// Pliki zmieniane też bez wtyczki (dziennik, sesje, blokada workera) - normalna praca rdzenia.
$coreDirs = [];
foreach (array_keys($coreChanges) as $p) {
    $coreDirs[dirname($p)] = true;
}
$ownDir = "/var/www/crm/{$dest}/{$slug}/";
$fileRows = [];
foreach ($pluginChanges as $path => $what) {
    if (isset($coreChanges[$path]) || $path === rtrim($ownDir, '/') . '/.disabled' || $path === '/tmp/sbx-probe-cookies.txt') {
        continue;
    }
    if (str_starts_with($path, '/var/www/crm/storage/') && isset($coreDirs[dirname($path)]) && !str_starts_with($path, '/var/www/crm/storage/security/')) {
        continue;   // nowa sesja, nowy plik w katalogu, w którym rdzeń i tak pisze
    }
    $rel = str_replace('/var/www/crm/', '', $path);
    if (str_starts_with($path, $ownDir)) {
        finding('review', 'Pliki', 'Wtyczka zmienia własne pliki w trakcie działania.', "{$rel} ({$what})");
    } elseif (str_starts_with($path, '/var/www/crm/storage/security/')) {
        finding('critical', 'Pliki', 'Zmiana w storage/security (klucze, odciski wtyczek).', "{$rel} ({$what})");
    } elseif (str_starts_with($path, '/var/www/crm/storage/')) {
        finding('info', 'Pliki', 'Nowe dane w storage/.', "{$rel} ({$what})");
    } elseif (str_starts_with($path, '/var/www/')) {
        finding('critical', 'Pliki', 'Zmiana w kodzie aplikacji - tak wygląda podrzucona furtka.', "{$rel} ({$what})");
    } else {
        finding('review', 'Pliki', 'Plik zapisany poza katalogiem CRM.', "{$path} ({$what})");
    }
    $fileRows[] = [$rel, $what];
}
$canaryFilesBase = array_filter(explode("\n", trim($read('canary-files-base.txt'))));
foreach (array_diff(array_filter(explode("\n", trim($read('canary-files-plugin.txt')))), $canaryFilesBase) as $f) {
    finding('critical', 'Pliki', 'Sekret albo dane klienta zapisane jawnie do pliku.', str_replace('/var/www/crm/', '', $f));
}

// ------------------------------------------------------------ 3. procesy

$procs = array_diff(array_filter(array_map('trim', explode("\n", $read('procs-plugin.txt')))), array_filter(array_map('trim', explode("\n", $read('procs-base.txt')))));
foreach ($procs as $p) {
    if ($p !== '' && !str_contains($p, 'probe.php') && !str_contains($p, 'sh -c')) {
        finding('critical', 'Procesy', 'Proces zostawiony w tle po zakończeniu żądań.', mb_substr($p, 0, 200));
    }
}

// ------------------------------------------------------------ 4. baza danych

function normalizeSql(string $q): string
{
    $q = str_replace(['\\n', '\\t'], ' ', $q);
    $q = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/s", '?', $q) ?? $q;
    $q = preg_replace('/\b\d+(\.\d+)?\b/', '?', $q) ?? $q;
    $q = preg_replace('/\(\s*\?(\s*,\s*\?)*\s*\)/', '(?)', $q) ?? $q;
    $q = preg_replace('/\s+/', ' ', trim($q)) ?? $q;
    return mb_substr($q, 0, 400);
}
$qBase = array_flip(array_map('normalizeSql', array_filter(explode("\n", $read('queries-base.txt')))));
$qPlugin = array_unique(array_map('normalizeSql', array_filter(explode("\n", $read('queries-plugin.txt')))));
$newQueries = array_values(array_filter($qPlugin, static fn(string $q): bool => !isset($qBase[$q])));
$secretTables = ['admin_users', 'integrations', 'integration_accounts', 'mail_accounts'];
$extPrefix = 'ext_' . $slug;
foreach ($newQueries as $q) {
    $verb = strtoupper((string) strtok(ltrim($q, '( '), ' '));
    preg_match_all('/\b(?:from|join|into|update|table|exists)\s+`?([a-z0-9_]+)`?/i', $q, $m);
    $tables = array_unique(array_map('strtolower', $m[1]));
    $foreign = array_values(array_filter($tables, static fn(string $t): bool => !str_starts_with($t, $extPrefix) && !str_starts_with($t, 'ext_')));
    if (in_array($verb, ['DROP', 'TRUNCATE', 'RENAME', 'GRANT', 'REVOKE'], true) || preg_match('/^(CREATE|DROP|ALTER) USER|^SET GLOBAL|INTO OUTFILE|LOAD_FILE|LOAD DATA/i', $q)) {
        if ($foreign !== [] || preg_match('/USER|GLOBAL|OUTFILE|LOAD/i', $q)) {
            finding('critical', 'Baza', "Niebezpieczne polecenie {$verb} na danych rdzenia.", $q);
            continue;
        }
    }
    if ($verb === 'ALTER' && $foreign !== []) {
        finding('critical', 'Baza', 'Zmienia strukturę tabel rdzenia (ALTER).', $q);
        continue;
    }
    $hitSecret = array_values(array_intersect($foreign, $secretTables));
    $secretSetting = in_array('settings', $foreign, true) && preg_match('/(SECRET|PASSWORD|_PASS|TOKEN|_KEY)/i', $q) && !preg_match('/EXT_/i', $q);
    if ($hitSecret !== [] || $secretSetting) {
        $what = $hitSecret !== [] ? implode(', ', $hitSecret) : 'settings (klucze API)';
        $ownAccount = !$isExt && $hitSecret === ['integration_accounts'] && $verb === 'SELECT';
        finding($isExt ? 'critical' : ($ownAccount ? 'info' : 'review'), 'Baza',
            $isExt ? "Rozszerzenie czyta tabele z sekretami: {$what}." : "Zapytanie do tabel z sekretami: {$what}.", $q);
        continue;
    }
    if (in_array($verb, ['INSERT', 'UPDATE', 'DELETE', 'REPLACE'], true) && $foreign !== []) {
        // Rdzeń sam zapisuje inaczej w każdym przebiegu (nowe zamówienie, klient), więc to tylko informacja.
        finding('info', 'Baza', 'Zapis w tabelach rdzenia: ' . implode(', ', $foreign) . '.', $q);
    }
}

// ------------------------------------------------------------ 5. sieć

$events = array_values(array_filter(array_map(static fn($l) => json_decode($l, true), explode("\n", $read('net/network.jsonl')))));
$range = static fn(string $phase): array => [(int) trim($read("net-{$phase}-start.txt")), (int) trim($read("net-{$phase}-end.txt"))];
[$b0, $b1] = $range('base');
[$p0, $p1] = $range('plugin');
$sig = static fn(array $e): string => ($e['proto'] ?? '') . ' ' . strtolower((string) ($e['host'] ?? $e['name'] ?? $e['sni'] ?? '')) . ' ' . ($e['method'] ?? '') . ' ' . preg_replace('/\?.*/', '', (string) ($e['path'] ?? ''));
$baseSigs = [];
for ($i = $b0; $i < $b1 && $i < count($events); $i++) {
    $baseSigs[$sig($events[$i])] = true;
}

/** Wszystkie postacie treści, w których szukamy kanarków: jawna, URL, base64, JSON, gzip. */
function haystacks(string $raw): string
{
    $all = [$raw, rawurldecode($raw), urldecode($raw), stripcslashes($raw)];
    if (preg_match_all('/[A-Za-z0-9+\/_-]{16,}={0,2}/', $raw . ' ' . urldecode($raw), $m)) {
        foreach ($m[0] as $chunk) {
            $dec = base64_decode(strtr($chunk, '-_', '+/'), false);
            if ($dec !== false && $dec !== '') {
                $all[] = $dec;
                $all[] = (string) @gzdecode($dec);
                $all[] = (string) @gzinflate($dec);
                $all[] = stripcslashes($dec);
            }
        }
    }
    if (preg_match_all('/(?:[0-9a-f]{2}){12,}/i', $raw, $m)) {
        foreach ($m[0] as $hex) {
            $all[] = (string) @hex2bin($hex);
        }
    }
    return implode("\n", $all);
}
$netRows = [];
$hostsSeen = [];
for ($i = $p0; $i < $p1 && $i < count($events); $i++) {
    $e = $events[$i];
    $proto = (string) ($e['proto'] ?? '');
    $host = strtolower((string) ($e['host'] ?? $e['name'] ?? $e['sni'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;
    if ($proto === 'dns') {
        $hostsSeen[$host] ??= 'DNS';
        continue;
    }
    $isNew = !isset($baseSigs[$sig($e)]);
    $payload = ($e['path'] ?? '') . "\n" . json_encode($e['headers'] ?? []) . "\n" . base64_decode((string) ($e['body_b64'] ?? ''))
        . "\n" . base64_decode((string) ($e['auth_b64'] ?? '')) . "\n" . json_encode($e['mail_to'] ?? []);
    $text = haystacks($payload);
    $own = in_array($host, $ownHosts, true) || array_filter($ownHosts, static fn(string $h): bool => str_ends_with($host, '.' . $h)) !== [];
    $where = trim(strtoupper($proto) . ' ' . ($e['method'] ?? '') . ' ' . $host . (isset($e['path']) ? preg_replace('/\?.*/', '?…', (string) $e['path']) : ''));
    $leaks = [];
    foreach ($canaries['secret'] ?? [] as $label => $value) {
        if ($value !== '' && str_contains($text, (string) $value)) {
            // Sekret wysłany do usługi, do której należy (token Allegro do Allegro), to zwykłe zapytanie API.
            $home = array_filter((array) ($canaries['secret_hosts'][$label] ?? []),
                static fn(string $h): bool => $host === $h || str_ends_with($host, '.' . $h));
            if ($home !== []) {
                $leaks[] = "{$label} (do swojej usługi)";
                finding('info', 'Sieć', "Wysyła „{$label}” do usługi, do której ten sekret należy.", $where);
                continue;
            }
            $leaks[] = $label;
            finding('critical', 'Sieć', "Wysyła sekret „{$label}” na zewnątrz.", $where);
        }
    }
    foreach ($canaries['own'] ?? [] as $label => $value) {
        if ($value !== '' && str_contains($text, (string) $value)) {
            $leaks[] = "własny klucz {$label}";
            if (!$own) {
                finding('critical', 'Sieć', "Wysyła własny klucz API „{$label}” na serwer spoza listy adresów w kodzie wtyczki.", $where);
            }
        }
    }
    foreach ($canaries['pii'] ?? [] as $label => $value) {
        if ($value !== '' && str_contains($text, (string) $value)) {
            $leaks[] = $label;
            finding($isExt || !$own ? 'critical' : 'review', 'Sieć', "Wysyła dane klienta ({$label}) na zewnątrz.", $where);
        }
    }
    if (!empty($e['tls_failed'])) {
        finding('review', 'Sieć', 'Połączenie szyfrowane, którego sandbox nie mógł podejrzeć (własna lista CA albo przypięty certyfikat).', $host);
    }
    if ($isNew && $leaks === []) {
        finding($isExt || !$own ? 'review' : 'info', 'Sieć', $isExt ? 'Rozszerzenie łączy się z internetem.' : ($own ? 'Połączenie z API wtyczki.' : 'Połączenie z adresem, którego nie ma w kodzie wtyczki.'), $where);
    }
    if ($isNew || $leaks !== []) {
        $netRows[] = [$where, $leaks === [] ? '-' : implode(', ', $leaks)];
        $hostsSeen[$host] = strtoupper($proto);
    }
}

// ------------------------------------------------------------ 6. strony, wywołania, worker

$pagesBase = $json('pages-base.json');
$pagesPlugin = $json('pages-plugin.json');
if (($pagesPlugin['login'] ?? false) !== true) {
    finding('review', 'Panel', 'Logowanie do panelu nie powiodło się w przebiegu z wtyczką.');
}
$pageRows = [];
foreach ($pagesPlugin['pages'] ?? [] as $page => $p) {
    $b = $pagesBase['pages'][$page] ?? null;
    $broken = $p['error'] !== '' && ($b === null || $b['error'] === '');
    if ($broken) {
        finding('review', 'Panel', 'Strona przestaje działać z wtyczką.', "{$page}: {$p['error']}");
    }
    $slower = $b !== null && $p['ms'] > $b['ms'] + 3000;
    if ($slower) {
        finding('info', 'Panel', 'Strona wyraźnie wolniejsza z wtyczką.', "{$page}: {$b['ms']} ms → {$p['ms']} ms");
    }
    $pageRows[] = [$page, ($b['status'] ?? '-') . ' → ' . $p['status'], ($b['ms'] ?? '-') . ' → ' . $p['ms'] . ' ms', $p['error'] !== '' ? $p['error'] : '-'];
}
if (($pagesPlugin['worker']['exit'] ?? 0) !== 0 && ($pagesBase['worker']['exit'] ?? 0) === 0) {
    finding('review', 'Worker', 'Worker kończy się błędem z wtyczką.', mb_substr((string) $pagesPlugin['worker']['output'], -300));
}
foreach (explode("\n", $read('app.log')) as $line) {
    if (preg_match('/Rozszerzenie ' . preg_quote($slug, '/') . '\b|Plugin.*' . preg_quote($slug, '/') . '|PHP (Fatal|Warning|Parse)/i', $line)) {
        finding('info', 'Dziennik', 'Wpis w dzienniku.', mb_substr(trim($line), 0, 300));
    }
}

// ------------------------------------------------------------ werdykt i raport

$count = ['critical' => 0, 'review' => 0, 'info' => 0];
foreach ($findings as $f) {
    $count[$f[0]]++;
}
$verdict = $count['critical'] > 0 ? 'ODRZUCONA' : ($count['review'] > 0 ? 'DO PRZEGLĄDU' : 'BEZ ZASTRZEŻEŃ');
$icon = ['critical' => '🔴', 'review' => '🟡', 'info' => '⚪'];
$label = ['critical' => 'Blokuje', 'review' => 'Do sprawdzenia', 'info' => 'Informacja'];
usort($findings, static fn(array $a, array $b): int => array_search($a[0], ['critical', 'review', 'info'], true) <=> array_search($b[0], ['critical', 'review', 'info'], true));

$esc = static fn(string $s): string => str_replace(['|', "\n"], ['\\|', ' '], $s);
$md = "# Sandbox wtyczek: {$slug}\n\n";
$md .= "**Wynik: " . ($verdict === 'ODRZUCONA' ? '🔴' : ($verdict === 'DO PRZEGLĄDU' ? '🟡' : '🟢')) . " {$verdict}** "
    . "({$count['critical']} blokujących, {$count['review']} do sprawdzenia, {$count['info']} informacji)\n\n";
$md .= "Rodzaj: " . ($isExt ? 'rozszerzenie' : 'integracja') . ", plików: " . ($scan['files'] ?? 0) . ", linii PHP: " . ($scan['lines'] ?? 0) . ".\n";
$md .= $verdict === 'ODRZUCONA'
    ? "Wtyczka zrobiła albo zawiera coś, czego nie wolno. Nie instaluj jej.\n\n"
    : "Sandbox nie znalazł niczego blokującego. Przed dodaniem do katalogu człowiek i tak przegląda kod i punkty „do sprawdzenia”.\n\n";
$md .= "## Znaleziska\n\n";
if ($findings === []) {
    $md .= "Brak.\n\n";
} else {
    $md .= "| | Obszar | Co | Gdzie |\n|---|---|---|---|\n";
    foreach ($findings as [$lvl, $area, $msg, $det]) {
        $md .= "| {$icon[$lvl]} {$label[$lvl]} | {$area} | {$esc($msg)} | {$esc($det)} |\n";
    }
    $md .= "\n";
}
$md .= "## Sieć (tylko z wtyczką)\n\n";
if ($netRows === []) {
    $md .= "Brak połączeń, których nie było bez wtyczki.\n\n";
} else {
    $md .= "| Połączenie | Wyniesione kanarki |\n|---|---|\n";
    foreach ($netRows as [$w, $l]) {
        $md .= "| {$esc($w)} | {$esc($l)} |\n";
    }
    $md .= "\n";
}
if (($scan['hosts'] ?? []) !== []) {
    $md .= "Adresy zapisane w kodzie wtyczki: " . implode(', ', array_map(static fn($h) => "`{$h}`", $scan['hosts'])) . "\n\n";
}
$md .= "## Pliki (tylko z wtyczką)\n\n";
$md .= $fileRows === [] ? "Bez zmian poza katalogami, w których pisze sam CRM.\n\n"
    : implode("\n", array_map(static fn($r) => "- `{$r[0]}` ({$r[1]})", array_slice($fileRows, 0, 50))) . "\n\n";
$md .= "## Baza (zapytania, których nie było bez wtyczki): " . count($newQueries) . "\n\n";
if ($newQueries !== []) {
    $md .= "<details><summary>Pokaż</summary>\n\n```sql\n" . implode("\n", array_slice($newQueries, 0, 80)) . "\n```\n</details>\n\n";
}
if (($pagesPlugin['calls'] ?? []) !== []) {
    $md .= "## Wywołania integracji (fałszywe klucze, fałszywy internet)\n\n| Wywołanie | Wynik |\n|---|---|\n";
    foreach ($pagesPlugin['calls'] as $c) {
        $md .= "| `{$c['call']}` | " . $esc($c['ok'] ? 'OK ' . ($c['result'] ?? '') : 'błąd: ' . ($c['error'] ?? '')) . " |\n";
    }
    $md .= "\n";
}
$md .= "## Strony panelu (bez wtyczki → z wtyczką)\n\n<details><summary>Pokaż</summary>\n\n| Strona | HTTP | Czas | Błąd |\n|---|---|---|---|\n";
foreach ($pageRows as $r) {
    $md .= '| ' . implode(' | ', array_map($esc, array_map('strval', $r))) . " |\n";
}
$md .= "\n</details>\n\n";
$md .= "## Jak to sprawdzono\n\nCRM uruchomiony w Dockerze na fikcyjnych danych, bez dostępu do internetu. Tokeny, klucze API, hasła i dane klientów "
    . "to unikalne „kanarki” - jeśli któryś pojawi się w ruchu sieciowym albo w pliku, wtyczka go wyniosła. Ruch HTTPS jest odszyfrowywany "
    . "certyfikatem sandboxu. Przebieg bez wtyczki i z wtyczką (te same strony panelu, nowe zamówienie z automatyzacjami, zmiana statusu, "
    . "worker) - w raporcie jest tylko różnica. Sandbox nie wykryje wszystkiego: kodu, który czeka na konkretną datę albo zamówienie, "
    . "ani połączeń prosto na adres IP z pominięciem DNS. Dlatego wynik „do przeglądu” i „bez zastrzeżeń” to wciąż nie zgoda - "
    . "decyzję podejmuje człowiek po przeczytaniu kodu.\n";

file_put_contents("$dir/raport.md", $md);
file_put_contents("$dir/raport.json", json_encode(['plugin' => $slug, 'kind' => $kind, 'verdict' => $verdict, 'counts' => $count,
    'findings' => array_map(static fn($f) => ['level' => $f[0], 'area' => $f[1], 'message' => $f[2], 'details' => $f[3]], $findings),
    'network' => $netRows, 'files' => $fileRows, 'new_queries' => $newQueries], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

echo "Wynik: {$verdict} ({$count['critical']} blokujących, {$count['review']} do sprawdzenia, {$count['info']} informacji)\n";
foreach ($findings as [$lvl, $area, $msg, $det]) {
    if ($lvl !== 'info') {
        echo "  {$icon[$lvl]} [{$area}] {$msg}" . ($det !== '' ? " - " . mb_substr($det, 0, 160) : '') . "\n";
    }
}
exit($verdict === 'ODRZUCONA' ? 1 : 0);
