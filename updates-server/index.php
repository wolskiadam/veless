<?php
declare(strict_types=1);

/**
 * Serwer aktualizacji Veless. Wgrywa się go na hosting Klubu (osobny katalog, np. aktualizacje.klubwoskarzy.pl).
 *
 *   ?action=check     - pyta zainstalowany Veless (raz dziennie): podaje swoją wersję, identyfikator instalacji,
 *                       adres panelu i wersję PHP; dostaje opis najnowszej wersji. Zapis trafia na listę instalacji.
 *   ?action=download  - pobranie paczki veless-<wersja>.zip (podpisanej w GitHub Actions; Veless sprawdza podpis).
 *   ?action=installs  - lista instalacji z wersjami (tylko z hasłem admin_key z config.php).
 *
 * Paczki: katalog releases/ (veless-<wersja>.zip). Opcjonalnie releases/veless-<wersja>.json:
 *   {"auto": false}  - ta wersja nie instaluje się automatycznie (tylko po kliknięciu w panelu klienta),
 *   {"notes": "…"}   - opis zmian pokazywany w panelu (domyślnie z paczki).
 *
 * Miejsce na licencje (Club Manager): funkcja upd_allowed() - na razie wszyscy dostają aktualizacje.
 */

$cfg = is_file(__DIR__ . '/config.php') ? (array) require __DIR__ . '/config.php' : [];
$cfg += ['admin_key' => '', 'releases_dir' => __DIR__ . '/releases', 'data_dir' => __DIR__ . '/data'];
if ($env = getenv('VELESS_UPDATES_DIR')) { $cfg['releases_dir'] = $env . '/releases'; $cfg['data_dir'] = $env . '/data'; } // tylko testy

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function upd_json(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function upd_h(mixed $v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

/** @return array<string,array{version:string,file:string,size:int,sha256:string,created_at:string,notes:string,auto:bool}> wersja => opis */
function upd_releases(string $dir, string $dataDir): array
{
    $cacheFile = $dataDir . '/releases-cache.json';
    $cache = is_file($cacheFile) ? (json_decode((string) file_get_contents($cacheFile), true) ?: []) : [];
    $out = []; $dirty = false;
    foreach (glob($dir . '/veless-*.zip') ?: [] as $path) {
        if (!preg_match('/^veless-(\d+(?:\.\d+){1,3})\.zip$/D', basename($path), $m)) { continue; }
        $key = basename($path) . ':' . filesize($path) . ':' . filemtime($path);
        if (!isset($cache[$key])) {
            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::RDONLY) !== true) { continue; }
            $manifest = json_decode((string) $zip->getFromName('package.json'), true);
            $signed = $zip->getFromName('package.sig') !== false;
            $zip->close();
            if (!is_array($manifest) || ($manifest['version'] ?? '') !== $m[1]) { continue; }
            $cache[$key] = ['version' => $m[1], 'size' => (int) filesize($path), 'sha256' => hash_file('sha256', $path),
                'created_at' => (string) ($manifest['created_at'] ?? ''), 'notes' => (string) ($manifest['notes'] ?? ''), 'signed' => $signed];
            $dirty = true;
        }
        $meta = is_file($dir . '/veless-' . $m[1] . '.json') ? (json_decode((string) file_get_contents($dir . '/veless-' . $m[1] . '.json'), true) ?: []) : [];
        $out[$m[1]] = $cache[$key] + ['file' => $path];
        if (isset($meta['notes'])) { $out[$m[1]]['notes'] = (string) $meta['notes']; }
        $out[$m[1]]['auto'] = ($meta['auto'] ?? true) !== false;
    }
    if ($dirty && is_dir($dataDir)) { @file_put_contents($cacheFile, json_encode($cache), LOCK_EX); }
    uksort($out, 'version_compare');
    return $out;
}

/** Zapis instalacji (identyfikator → adres, wersja, PHP, pierwsze/ostatnie zgłoszenie). */
function upd_record(string $dataDir, string $id, array $info): void
{
    if (!is_dir($dataDir) && !@mkdir($dataDir, 0700, true)) { return; }
    $file = $dataDir . '/installs.json';
    $fh = @fopen($file, 'c+');
    if (!$fh) { return; }
    try {
        flock($fh, LOCK_EX);
        $all = json_decode((string) stream_get_contents($fh), true) ?: [];
        $now = gmdate('c');
        $all[$id] = $info + ['first_seen' => $all[$id]['first_seen'] ?? $now, 'last_seen' => $now];
        ftruncate($fh, 0); rewind($fh);
        fwrite($fh, json_encode($all, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        fflush($fh);
    } finally { flock($fh, LOCK_UN); fclose($fh); }
}

/** Czy ta instalacja dostaje aktualizacje. Tu w przyszłości sprawdzenie licencji (Club Manager). */
function upd_allowed(string $installId, string $licence): bool
{
    return true;
}

$action = (string) ($_GET['action'] ?? '');
$selfUrl = (((($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http')
    . '://' . preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'))
    . strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');

if ($action === 'check') {
    $id = (string) ($_GET['install'] ?? '');
    $version = (string) ($_GET['version'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/D', $id) || !preg_match('/^\d+(\.\d+){1,3}$/D', $version)) {
        upd_json(['error' => 'bad request'], 400);
    }
    $site = substr(preg_replace('/[^\w.\-:\/]/u', '', (string) ($_GET['site'] ?? '')), 0, 200);
    $php = substr(preg_replace('/[^0-9.]/', '', (string) ($_GET['php'] ?? '')), 0, 20);
    $licence = substr(preg_replace('/[^A-Za-z0-9\-]/', '', (string) ($_GET['licence'] ?? '')), 0, 100);
    upd_record($cfg['data_dir'], $id, ['site' => $site, 'version' => $version, 'php' => $php, 'licence' => $licence]);
    if (!upd_allowed($id, $licence)) {
        upd_json(['latest' => null, 'message' => 'Subskrypcja nieaktywna - system działa dalej, ale bez nowych wersji.']);
    }
    // Tylko podpisane paczki (niepodpisanej zainstalowany Veless i tak by nie przyjął).
    $releases = array_filter(upd_releases($cfg['releases_dir'], $cfg['data_dir']), static fn($r) => $r['signed']);
    $latest = $releases ? end($releases) : null;
    if ($latest === null) {
        upd_json(['latest' => null]);
    }
    upd_json(['latest' => [
        'version' => $latest['version'], 'size' => $latest['size'], 'sha256' => $latest['sha256'], 'created_at' => $latest['created_at'],
        'notes' => $latest['notes'], 'auto' => $latest['auto'],
        'download' => $selfUrl . '?action=download&version=' . rawurlencode($latest['version']),
    ]]);
}

if ($action === 'download') {
    $version = (string) ($_GET['version'] ?? '');
    $releases = upd_releases($cfg['releases_dir'], $cfg['data_dir']);
    if (!isset($releases[$version])) { http_response_code(404); exit('Nie ma takiej wersji.'); }
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/zip');
    header('Content-Length: ' . $releases[$version]['size']);
    header('Content-Disposition: attachment; filename="veless-' . $version . '.zip"');
    readfile($releases[$version]['file']);
    exit;
}

if ($action === 'installs') {
    $key = (string) ($_GET['key'] ?? '');
    if ($cfg['admin_key'] === '' || !hash_equals((string) $cfg['admin_key'], $key)) { http_response_code(403); exit('Brak dostępu.'); }
    $all = json_decode((string) @file_get_contents($cfg['data_dir'] . '/installs.json'), true) ?: [];
    uasort($all, static fn($a, $b) => strcmp((string) $b['last_seen'], (string) $a['last_seen']));
    $releases = upd_releases($cfg['releases_dir'], $cfg['data_dir']);
    $newest = $releases ? array_key_last($releases) : '';
    header('Content-Type: text/html; charset=utf-8');
    $rows = '';
    foreach ($all as $id => $i) {
        $old = $newest !== '' && version_compare((string) $i['version'], $newest, '<');
        $stale = strtotime((string) $i['last_seen']) < time() - 3 * 86400;
        $rows .= '<tr><td>' . upd_h($i['site'] ?: '—') . '</td><td' . ($old ? ' class="old"' : '') . '>' . upd_h($i['version']) . '</td><td>' . upd_h($i['php'])
            . '</td><td' . ($stale ? ' class="old"' : '') . '>' . upd_h(substr((string) $i['last_seen'], 0, 16)) . '</td><td>' . upd_h(substr((string) $i['first_seen'], 0, 10))
            . '</td><td><code>' . upd_h(substr((string) $id, 0, 8)) . '</code></td></tr>';
    }
    echo '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
        . '<title>Instalacje Veless</title><style>body{font:14px/1.5 system-ui,sans-serif;margin:24px 16px;color:#1d2330;background:#f4f5f7}'
        . 'main{max-width:960px;margin:auto;background:#fff;border:1px solid #dde1e7;border-radius:10px;padding:20px;overflow-x:auto}'
        . 'table{border-collapse:collapse;width:100%}th,td{text-align:left;padding:6px 8px;border-bottom:1px solid #e6e9ee;white-space:nowrap}'
        . 'th{font-size:12px;color:#5b6475}.old{color:#b42318;font-weight:600}</style></head><body><main>'
        . '<h1 style="font-size:20px;margin:0 0 4px">Instalacje Veless</h1><p style="color:#5b6475;margin:0 0 14px">Najnowsza wersja: <strong>' . upd_h($newest ?: '—')
        . '</strong>. Instalacji: ' . count($all) . '. Na czerwono: starsza wersja albo brak kontaktu od 3 dni (cron nie działa lub system wyłączony).</p>'
        . '<table><thead><tr><th>Adres panelu</th><th>Wersja</th><th>PHP</th><th>Ostatnio (UTC)</th><th>Od</th><th>ID</th></tr></thead><tbody>' . $rows . '</tbody></table></main></body></html>';
    exit;
}

upd_json(['service' => 'veless-updates']);
