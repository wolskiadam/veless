<?php
declare(strict_types=1);

/**
 * Kreator instalacji Veless (w paczce wydania jako instaluj.php, patrz cli/build_release.php).
 *
 * Użytkownik wgrywa na hosting dwa pliki: instaluj.php i veless-<wersja>.zip, a potem otwiera
 * instaluj.php w przeglądarce:
 *   1. wymagania serwera,
 *   2. dane pustej bazy, adres panelu i konto administratora,
 *   3. rozpakowanie plików w porcjach (limity czasu shared hostingu) z kontrolą sum SHA-256,
 *   4. .env, klucz szyfrowania, tabele (te same migracje co w działającym systemie), konto admina,
 *   5. kontrola, czy .env i klucze nie są widoczne z internetu, oraz cron z testem,
 *   6. usunięcie paczki, plików roboczych i samego kreatora.
 *
 * Samodzielny plik: przed rozpakowaniem nie ma jeszcze kodu Veless. Funkcje mają prefiks vi_,
 * bo w kroku 4 kreator ładuje config/config.php systemu w tym samym procesie.
 *
 * Kreator przejmuje pierwsza przeglądarka, która go uruchomi (ciasteczko). Inne dostają odmowę,
 * żeby nikt obcy nie dokończył instalacji z własną bazą.
 */

// Kopia leżąca w src/Installer/ działającego systemu nigdy nic nie robi.
if (basename(__DIR__) === 'Installer' || is_file(dirname(__DIR__) . '/Support/AppVersion.php')) { http_response_code(404); exit; }
if (PHP_SAPI === 'cli') { fwrite(STDERR, "Otwórz instaluj.php w przeglądarce.\n"); exit(1); }

define('VI_BUDGET', (float) (getenv('VELESS_INSTALLER_BUDGET') ?: 15)); // sekund pracy na jedno żądanie (env tylko dla testów)
const VI_BLOCK_BEGIN = '# BEGIN veless-installer';
const VI_BLOCK_END = '# END veless-installer';
const VI_MIN_PASSWORD = 10;

$started = microtime(true);
$dir = __DIR__;
$stateFile = $dir . '/.veless-install.json';
$self = basename(__FILE__);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$https = (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
session_name('veless_installer');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => $https, 'path' => '/']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));

// ------------------------------------------------------------------ pomocnicze

function vi_h(mixed $v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

/** Pasek kroków u góry strony. */
function vi_steps(int $current): string
{
    $names = ['Wymagania', 'Baza i konto', 'Pliki', 'Konfiguracja', 'Bezpieczeństwo i cron', 'Gotowe'];
    $out = '<ol class="steps">';
    foreach ($names as $i => $n) {
        $cls = $i + 1 < $current ? 'done' : ($i + 1 === $current ? 'now' : '');
        $out .= '<li class="' . $cls . '">' . vi_h($n) . '</li>';
    }
    return $out . '</ol>';
}

function vi_page(string $title, string $body, int $step = 0, bool $autoContinue = false): never
{
    $csrf = vi_h($_SESSION['csrf'] ?? '');
    $auto = $autoContinue
        ? '<form method="post" id="cont"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="continue">'
          . '<p><button type="submit">Kontynuuj</button></p></form><script>setTimeout(function(){document.getElementById("cont").submit();},300);</script>'
        : '';
    echo '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>' . vi_h($title) . ' - instalacja Veless</title><style>'
        . ':root{--bg:#f4f5f7;--card:#fff;--line:#dde1e7;--ink:#1d2330;--mute:#5b6475;--acc:#2f5bea}'
        . '@media (prefers-color-scheme:dark){:root{--bg:#14171c;--card:#1c2027;--line:#2c323c;--ink:#e6e9ee;--mute:#9aa3b2;--acc:#6b8cff}}'
        . 'body{font:15px/1.5 system-ui,sans-serif;background:var(--bg);color:var(--ink);margin:0;padding:24px 16px}'
        . 'main{max-width:680px;margin:0 auto;background:var(--card);border:1px solid var(--line);border-radius:10px;padding:24px}'
        . 'h1{font-size:20px;margin:0 0 12px}h2{font-size:16px;margin:20px 0 4px}label{display:block;font-size:13px;color:var(--mute);margin:12px 0 4px}'
        . 'input{width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid var(--line);border-radius:6px;font:inherit;background:var(--card);color:var(--ink)}'
        . 'button{margin-top:16px;padding:9px 18px;border:0;border-radius:6px;background:var(--acc);color:#fff;font:inherit;cursor:pointer}'
        . 'button.sec{background:transparent;color:var(--acc);border:1px solid var(--acc)}button[disabled]{opacity:.5;cursor:not-allowed}'
        . '.err{background:#fdecec;color:#8a1c1c;padding:10px 12px;border-radius:6px;margin:12px 0}'
        . '.ok{background:#e9f7ee;color:#1d5c34;padding:10px 12px;border-radius:6px;margin:12px 0}'
        . '.warn{background:#fff6e0;color:#6b4a00;padding:10px 12px;border-radius:6px;margin:12px 0}'
        . '.hint{font-size:13px;color:var(--mute)}code,pre{background:rgba(127,127,127,.14);border-radius:4px;padding:2px 5px;font-size:13px;word-break:break-all;white-space:pre-wrap}'
        . 'pre{padding:8px 10px}li{margin:6px 0}progress{width:100%}table{border-collapse:collapse;width:100%}td{padding:6px 4px;border-bottom:1px solid var(--line);vertical-align:top}'
        . 'td.st{width:28px;font-weight:700}.steps{display:flex;flex-wrap:wrap;gap:4px 14px;list-style:none;padding:0;margin:0 0 16px;font-size:12px;color:var(--mute)}'
        . '.steps li{margin:0}.steps li.now{color:var(--acc);font-weight:600}.steps li.done::before{content:"✓ "}'
        . '.row{display:flex;gap:12px}.row>div{flex:1}@media (max-width:520px){.row{display:block}}</style></head><body><main>'
        . ($step > 0 ? vi_steps($step) : '') . '<h1>' . vi_h($title) . '</h1>'
        . $body . $auto . '</main></body></html>';
    exit;
}

function vi_fail(string $message, string $extra = '', int $step = 0): never
{
    vi_page('Instalacja Veless', '<div class="err">' . vi_h($message) . '</div>' . $extra, $step);
}

function vi_csrf_check(): void
{
    if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_POST['csrf'] ?? ''))) {
        vi_fail('Sesja wygasła. Odśwież stronę i spróbuj ponownie.');
    }
}

function vi_hidden(): string
{
    return '<input type="hidden" name="csrf" value="' . vi_h($_SESSION['csrf'] ?? '') . '">';
}

function vi_load_state(string $file): ?array
{
    if (!is_file($file)) { return null; }
    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function vi_save_state(string $file, array $state): void
{
    $tmp = $file . '.tmp';
    if (file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false || !rename($tmp, $file)) {
        throw new RuntimeException('Nie można zapisać postępu instalacji (brak miejsca lub uprawnień do katalogu).');
    }
    @chmod($file, 0600);
}

function vi_safe_entry(string $name): bool
{
    if ($name === '' || str_starts_with($name, '/') || str_contains($name, '\\') || str_contains($name, ':')
        || preg_match('/[\x00-\x1f]/', $name) || str_contains($name, '//') || str_ends_with($name, '/')) { return false; }
    foreach (explode('/', $name) as $part) { if ($part === '.' || $part === '..') { return false; } }
    return true;
}

/** @return array{0:ZipArchive,1:array} */
function vi_open_package(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) { throw new RuntimeException('Nie można otworzyć paczki ' . basename($path) . ' - plik jest uszkodzony lub wgrany tylko w części (wgraj go ponownie, w FTP w trybie binarnym).'); }
    $stat = $zip->statName('package.json');
    $raw = $stat && $stat['size'] < 16 * 1024 * 1024 ? $zip->getFromName('package.json') : false;
    $manifest = $raw === false ? null : json_decode($raw, true);
    if (!is_array($manifest) || ($manifest['format'] ?? '') !== 'veless-release-v1' || !is_array($manifest['files'] ?? null)) {
        $zip->close();
        throw new RuntimeException('Plik ' . basename($path) . ' nie jest paczką instalacyjną Veless. Pobierz ją ponownie z konta subskrybenta.');
    }
    return [$zip, $manifest];
}

/** Gdzie trafia wpis z paczki (null = pomiń). */
function vi_target(string $entry, string $dir, string $self): ?string
{
    if (!str_starts_with($entry, 'app/')) { return null; }
    $rel = substr($entry, 4);
    if ($rel === '.env' || $rel === $self || str_starts_with($rel, 'storage/installer/') || str_starts_with($rel, '.veless-install')) { return null; }
    return $dir . '/' . $rel;
}

function vi_extract(ZipArchive $zip, string $entry, array $expected, string $target): void
{
    $parent = dirname($target);
    if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) { throw new RuntimeException('Nie można utworzyć katalogu: ' . $parent); }
    if (is_link($target)) { throw new RuntimeException('W miejscu pliku jest dowiązanie symboliczne: ' . $target); }
    $stream = $zip->getStream($entry);
    if (!$stream) { throw new RuntimeException('Nie można odczytać z paczki: ' . $entry); }
    $tmp = $target . '.vi-part';
    $out = fopen($tmp, 'wb');
    if (!$out) { fclose($stream); throw new RuntimeException('Nie można zapisać pliku: ' . $target . ' (sprawdź prawa zapisu do katalogu).'); }
    $hash = hash_init('sha256'); $bytes = 0;
    try {
        while (!feof($stream)) {
            $chunk = fread($stream, 1024 * 1024);
            if ($chunk === false) { throw new RuntimeException('Błąd odczytu paczki: ' . $entry); }
            if ($chunk === '') { if (feof($stream)) { break; } throw new RuntimeException('Błąd odczytu paczki: ' . $entry); }
            $bytes += strlen($chunk); hash_update($hash, $chunk);
            if (fwrite($out, $chunk) !== strlen($chunk)) { throw new RuntimeException('Brak miejsca na dysku przy zapisie: ' . $entry); }
        }
    } finally { fclose($stream); fclose($out); }
    if ($bytes !== (int) $expected['size'] || !hash_equals((string) $expected['sha256'], hash_final($hash))) {
        @unlink($tmp);
        throw new RuntimeException('Suma kontrolna się nie zgadza (paczka uszkodzona przy wgrywaniu): ' . $entry);
    }
    if (!rename($tmp, $target)) { @unlink($tmp); throw new RuntimeException('Nie można zapisać pliku: ' . $target); }
    @chmod($target, 0644);
}

function vi_connect(array $db): PDO
{
    $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']),
        $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 10]);
    return $pdo;
}

function vi_env_value(string $v): string
{
    return '"' . $v . '"';
}

/** .env z .env.example: dane bazy, adres, losowy sekret workera, bez domyślnego hasła admina. */
function vi_build_env(string $template, array $db, string $baseUrl): string
{
    $set = [
        'APP_ENV' => 'production', 'APP_MODE' => 'server', 'APP_DEBUG' => 'false', 'DEMO_MODE' => '0',
        'WORKER_HTTP_SECRET' => vi_env_value(bin2hex(random_bytes(24))), 'ADMIN_DEFAULT_PASSWORD' => '',
        'DB_HOST' => vi_env_value($db['host']), 'DB_PORT' => (string) $db['port'], 'DB_NAME' => vi_env_value($db['name']),
        'DB_USER' => vi_env_value($db['user']), 'DB_PASS' => vi_env_value($db['pass']),
        'ALLEGRO_REDIRECT_URI' => vi_env_value($baseUrl . '/auth_allegro_callback.php'),
    ];
    $out = []; $done = [];
    foreach (preg_split('/\r\n|\n|\r/', $template) as $line) {
        $trim = trim($line);
        if ($trim !== '' && $trim[0] !== '#' && str_contains($trim, '=')) {
            $key = trim(explode('=', $trim, 2)[0]);
            if (array_key_exists($key, $set)) {
                if (!isset($done[$key])) { $out[] = $key . '=' . $set[$key]; $done[$key] = true; }
                continue;
            }
        }
        $out[] = $line;
    }
    while ($out !== [] && trim(end($out)) === '') { array_pop($out); }
    foreach ($set as $key => $value) { if (!isset($done[$key])) { $out[] = $key . '=' . $value; } }
    return implode("\n", $out) . "\n";
}

function vi_remove_tree(string $path): void
{
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) { return; }
    foreach (scandir($path) ?: [] as $entry) { if ($entry !== '.' && $entry !== '..') { vi_remove_tree($path . '/' . $entry); } }
    @rmdir($path);
}

/** Adres katalogu z kreatorem, np. https://domena.pl/veless */
function vi_root_url(bool $https): string
{
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $path = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/instaluj.php'))), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $path;
}

function vi_ini_bytes(string $v): int
{
    $v = trim($v);
    if ($v === '' || $v === '-1') { return -1; }
    $n = (int) $v;
    return match (strtolower(substr($v, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
}

/** @return list<array{0:string,1:string,2:string}> [poziom ok|warn|err, opis, podpowiedź] */
function vi_requirements(string $dir, ?string $package, ?string $packageError): array
{
    $r = [];
    $php = version_compare(PHP_VERSION, '8.1.0', '>=');
    $r[] = [$php ? 'ok' : 'err', 'PHP 8.1 lub nowszy (jest ' . PHP_VERSION . ')', $php ? '' : 'Zmień wersję PHP w panelu hostingu (np. „Wybór wersji PHP”).'];
    $ext = ['pdo_mysql' => 'połączenie z bazą MySQL/MariaDB', 'curl' => 'połączenia z Allegro, sklepami i kurierami', 'json' => 'dane w formacie JSON',
        'dom' => 'odczyt plików XML (faktury, kurierzy)', 'openssl' => 'szyfrowanie kluczy API', 'mbstring' => 'polskie znaki w tekstach', 'zip' => 'rozpakowanie paczki i kopie zapasowe'];
    foreach ($ext as $name => $why) {
        $ok = extension_loaded($name);
        $r[] = [$ok ? 'ok' : 'err', 'Rozszerzenie PHP ' . $name . ' (' . $why . ')', $ok ? '' : 'Włącz je w panelu hostingu w ustawieniach rozszerzeń PHP.'];
    }
    $gd = extension_loaded('gd');
    $r[] = [$gd ? 'ok' : 'warn', 'Rozszerzenie PHP gd (zdjęcia, logo, etykiety)', $gd ? '' : 'Bez niego system działa, ale nie przetworzy obrazków. Warto je włączyć.'];
    $w = is_writable($dir);
    $r[] = [$w ? 'ok' : 'err', 'Prawo zapisu do katalogu instalacji', $w ? '' : 'Nadaj prawa zapisu do ' . $dir . ' (zwykle 755) w menedżerze plików.'];
    $free = @disk_free_space($dir);
    if ($free !== false) {
        $r[] = [$free > 150 * 1048576 ? 'ok' : 'warn', 'Wolne miejsce na dysku: ' . number_format($free / 1048576, 0, ',', ' ') . ' MB', $free > 150 * 1048576 ? '' : 'Potrzeba co najmniej ok. 150 MB na pliki i pierwsze dane.'];
    }
    $mem = vi_ini_bytes((string) ini_get('memory_limit'));
    $r[] = [$mem === -1 || $mem >= 128 * 1048576 ? 'ok' : 'warn', 'Limit pamięci PHP: ' . ini_get('memory_limit'), $mem === -1 || $mem >= 128 * 1048576 ? '' : 'Zalecane co najmniej 128M (memory_limit w ustawieniach PHP).'];
    $soft = strtolower((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''));
    $apache = str_contains($soft, 'apache') || str_contains($soft, 'litespeed');
    $r[] = [$apache ? 'ok' : 'warn', 'Serwer WWW: ' . ($soft !== '' ? $_SERVER['SERVER_SOFTWARE'] : 'nieznany'),
        $apache ? '' : 'Veless chroni pliki konfiguracji przez .htaccess (Apache/LiteSpeed). Na innym serwerze kreator sprawdzi ochronę na końcu.'];
    if ($package !== null) {
        $r[] = ['ok', 'Paczka ' . basename($package), ''];
    } else {
        $r[] = ['err', 'Paczka veless-….zip obok instaluj.php', (string) $packageError];
    }
    return $r;
}

/** Ścieżka PHP do crona: ta sama wersja co strona, jeśli jej plik jest widoczny. */
function vi_php_cli(): string
{
    foreach ([PHP_BINDIR . '/php', '/usr/local/bin/php', '/usr/bin/php'] as $c) {
        if (@is_file($c) && @is_executable($c)) { return $c; }
    }
    return '/usr/bin/php';
}

/**
 * Czy plik jest dostępny z internetu? true = widoczny (źle), false = zablokowany, null = nie da się sprawdzić.
 * $needle - fragment treści, który zdradza, że serwer oddał plik.
 */
function vi_exposed(string $url, string $needle): ?bool
{
    if (!function_exists('curl_init')) { return null; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_RANGE => '0-4095', CURLOPT_USERAGENT => 'Veless-Installer/1']);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false || $code === 0) { return null; }
    return ($code === 200 || $code === 206) && str_contains((string) $body, $needle);
}

/** Blokada paczki i plików kreatora w .htaccess aplikacji, dopóki instalacja trwa. */
function vi_htaccess_block(string $file, bool $add): void
{
    $content = is_file($file) ? (string) file_get_contents($file) : '';
    $content = (string) preg_replace('/\n?' . preg_quote(VI_BLOCK_BEGIN, '/') . '.*?' . preg_quote(VI_BLOCK_END, '/') . '\n?/s', "\n", $content);
    $content = rtrim($content) . "\n";
    if ($add) {
        $content .= "\n" . VI_BLOCK_BEGIN . "\n<FilesMatch \"^(veless-.*\\.zip|\\.veless-install.*)$\">\n    Require all denied\n</FilesMatch>\n" . VI_BLOCK_END . "\n";
    }
    file_put_contents($file, ltrim($content, "\n"));
}

function vi_admin_form(array $db, array $admin, string $baseUrl, bool $https): string
{
    $v = static fn(array $src, string $k, string $d = '') => vi_h($src[$k] ?? $d);
    return '<form method="post" autocomplete="off">' . vi_hidden() . '<input type="hidden" name="action" value="configure">'
        . '<h2>Baza danych</h2><p class="hint">Utwórz wcześniej <strong>pustą</strong> bazę w panelu hostingu (MySQL lub MariaDB). '
        . 'Nazwy bazy i użytkownika mają tam zwykle prefiks z loginem, np. <code>login_veless</code>.</p>'
        . '<div class="row"><div><label>Host bazy</label><input name="db_host" value="' . $v($db, 'host', 'localhost') . '" required></div>'
        . '<div style="flex:0 0 110px"><label>Port</label><input name="db_port" value="' . $v($db, 'port', '3306') . '" inputmode="numeric" required></div></div>'
        . '<label>Nazwa bazy</label><input name="db_name" value="' . $v($db, 'name') . '" required>'
        . '<label>Użytkownik bazy</label><input name="db_user" value="' . $v($db, 'user') . '" required>'
        . '<label>Hasło bazy</label><input type="password" name="db_pass">'
        . '<h2>Adres panelu</h2>'
        . '<label>Adres katalogu public/ (wykryty automatycznie)</label><input name="base_url" value="' . vi_h($baseUrl) . '" required>'
        . '<p class="hint">Panel będzie pod tym adresem + <code>/admin/</code>. Zmień tylko, jeśli domena ma wskazywać prosto na katalog public/.'
        . ($https ? '' : ' <strong>Strona jest otwarta bez https.</strong> Jeśli domena ma certyfikat SSL, otwórz kreator przez https://.') . '</p>'
        . '<h2>Konto administratora</h2>'
        . '<div class="row"><div><label>Login</label><input name="admin_user" value="' . $v($admin, 'user', 'admin') . '" required pattern="[A-Za-z0-9._\-]{3,50}"></div>'
        . '<div><label>E-mail (do resetu hasła)</label><input type="email" name="admin_email" value="' . $v($admin, 'email') . '" required></div></div>'
        . '<div class="row"><div><label>Hasło (min. ' . VI_MIN_PASSWORD . ' znaków)</label><input type="password" name="admin_pass" required minlength="' . VI_MIN_PASSWORD . '" autocomplete="new-password"></div>'
        . '<div><label>Powtórz hasło</label><input type="password" name="admin_pass2" required minlength="' . VI_MIN_PASSWORD . '" autocomplete="new-password"></div></div>'
        . '<button type="submit">Dalej: rozpakuj i zainstaluj</button></form>';
}

// ------------------------------------------------------------------ przebieg

$state = vi_load_state($stateFile);
$action = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? (string) ($_POST['action'] ?? '') : '';
if ($action !== '') { vi_csrf_check(); }

// Instalację prowadzi jedna przeglądarka (ta, która ją zaczęła).
if ($state !== null && !hash_equals((string) ($state['claim'] ?? ''), hash('sha256', (string) ($_SESSION['claim'] ?? '')))) {
    vi_fail('Instalacja jest prowadzona w innej przeglądarce.', '<p>Jeśli to Ty i sesja się zgubiła (np. po zamknięciu przeglądarki), usuń przez FTP plik '
        . '<code>.veless-install.json</code> z katalogu instalacji i otwórz <code>' . vi_h($self) . '</code> ponownie.'
        . (($state['phase'] ?? '') !== 'extract' && ($state['phase'] ?? '') !== 'config' ? ' Usuń też plik <code>.env</code> i opróżnij bazę danych, bo konfiguracja była już zapisana.' : '') . '</p>');
}
if ($state === null && (is_file($dir . '/.env') || (is_file($dir . '/config/config.php') && is_file($dir . '/config/build.json')))) {
    vi_fail('W tym katalogu Veless jest już zainstalowany.', '<p>Kreator działa tylko w pustym katalogu. Usuń plik <code>' . vi_h($self) . '</code> z serwera, '
        . 'a do nowej instalacji użyj innego katalogu.</p>');
}

// Paczka obok kreatora.
$package = null; $packageError = null;
$candidates = array_values(array_filter(glob($dir . '/veless-*.zip') ?: [], static fn($p) => is_file($p) && preg_match('/^veless-\d+(\.\d+){1,3}\.zip$/D', basename($p))));
if (count($candidates) === 1) {
    $package = $candidates[0];
} elseif (count($candidates) === 0) {
    $packageError = glob($dir . '/veless-*-instalacja.zip')
        ? 'Wgrany jest plik „…-instalacja.zip”. Rozpakuj go u siebie na komputerze i wgraj na serwer to, co jest w środku: instaluj.php i veless-<wersja>.zip.'
        : 'Wgraj plik veless-<wersja>.zip do tego samego katalogu co instaluj.php.';
} else {
    $packageError = 'W katalogu jest kilka paczek veless-….zip. Zostaw tylko tę, którą chcesz zainstalować.';
}
$manifest = null;
if ($package !== null) {
    try { [$z, $manifest] = vi_open_package($package); $z->close(); }
    catch (Throwable $e) { $packageError = $e->getMessage(); $package = null; }
}

try {
    // 1. Wymagania.
    if ($state === null) {
        $req = vi_requirements($dir, $package, $packageError);
        $blocked = (bool) array_filter($req, static fn($r) => $r[0] === 'err');
        if ($action === 'start' && !$blocked) {
            session_regenerate_id(true);
            $_SESSION['claim'] = bin2hex(random_bytes(24));
            $state = ['phase' => 'config', 'claim' => hash('sha256', $_SESSION['claim']), 'index' => 0, 'version' => (string) $manifest['version'], 'started_at' => time()];
            vi_save_state($stateFile, $state);
            header('Location: ' . $self, true, 303);
            exit;
        }
        $icons = ['ok' => '<span style="color:#1d7a43">✓</span>', 'warn' => '<span style="color:#a06a00">!</span>', 'err' => '<span style="color:#b42318">✗</span>'];
        $rows = '';
        foreach ($req as [$lvl, $label, $hint]) {
            $rows .= '<tr><td class="st">' . $icons[$lvl] . '</td><td>' . vi_h($label) . ($hint !== '' ? '<div class="hint">' . vi_h($hint) . '</div>' : '') . '</td></tr>';
        }
        vi_page('Instalacja Veless' . ($manifest ? ' ' . $manifest['version'] : ''),
            '<p>Kreator zainstaluje system w katalogu <code>' . vi_h($dir) . '</code>. Najpierw sprawdzamy, czy serwer ma wszystko, czego potrzeba.</p>'
            . '<table>' . $rows . '</table>'
            . ($blocked ? '<div class="err">Popraw pozycje oznaczone ✗ i odśwież tę stronę.</div>' : '')
            . '<form method="post">' . vi_hidden() . '<input type="hidden" name="action" value="start"><button type="submit"' . ($blocked ? ' disabled' : '') . '>Rozpocznij instalację</button></form>', 1);
    }

    if ($package === null || $manifest === null) {
        vi_fail((string) $packageError, '<p class="hint">Postęp jest zapisany - po wgraniu paczki odśwież stronę.</p>');
    }
    if ((string) $manifest['version'] !== (string) $state['version']) {
        vi_fail('Paczka w katalogu (' . $manifest['version'] . ') jest inna niż na początku instalacji (' . $state['version'] . ').',
            '<p>Wgraj z powrotem tę samą paczkę albo usuń plik <code>.veless-install.json</code> i zacznij od nowa.</p>');
    }

    // 2. Baza, adres i konto administratora.
    if ($state['phase'] === 'config' || !isset($_SESSION['db'])) {
        $error = ''; $db = $_SESSION['db'] ?? []; $admin = $_SESSION['admin_form'] ?? [];
        $baseUrl = $state['base_url'] ?? vi_root_url($https) . '/public';
        if ($state['phase'] !== 'config') {
            // Zgubiona sesja w trakcie (np. restart serwera) - wystarczy ponownie podać dane bazy.
            $error = 'Podaj ponownie dane bazy, żeby dokończyć instalację.';
        }
        if ($action === 'configure') {
            $db = ['host' => trim((string) ($_POST['db_host'] ?? '')), 'port' => (int) ($_POST['db_port'] ?? 0), 'name' => trim((string) ($_POST['db_name'] ?? '')),
                'user' => trim((string) ($_POST['db_user'] ?? '')), 'pass' => (string) ($_POST['db_pass'] ?? '')];
            $admin = ['user' => trim((string) ($_POST['admin_user'] ?? '')), 'email' => trim((string) ($_POST['admin_email'] ?? ''))];
            $_SESSION['admin_form'] = $admin;
            $baseUrl = rtrim(trim((string) ($_POST['base_url'] ?? '')), '/');
            try {
                if ($db['host'] === '' || $db['name'] === '' || $db['user'] === '' || $db['port'] < 1 || $db['port'] > 65535) { throw new RuntimeException('Uzupełnij dane bazy.'); }
                if (preg_match('/[\r\n"\\\\$`]/', implode('', [$db['host'], $db['name'], $db['user'], $db['pass']]))) { throw new RuntimeException('Dane bazy nie mogą zawierać znaków " \\ $ ` ani nowej linii. Zmień hasło bazy w panelu hostingu.'); }
                if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $baseUrl) || preg_match('/[\s"]/', $baseUrl)) { throw new RuntimeException('Podaj pełny adres, np. https://twoja-domena.pl/veless/public'); }
                if ($state['phase'] === 'config') {
                    if (!preg_match('/^[A-Za-z0-9._\-]{3,50}$/D', $admin['user'])) { throw new RuntimeException('Login administratora: 3-50 znaków, litery, cyfry, kropka, myślnik lub podkreślenie.'); }
                    if (!filter_var($admin['email'], FILTER_VALIDATE_EMAIL)) { throw new RuntimeException('Podaj poprawny e-mail administratora.'); }
                    $p1 = (string) ($_POST['admin_pass'] ?? ''); $p2 = (string) ($_POST['admin_pass2'] ?? '');
                    if (mb_strlen($p1) < VI_MIN_PASSWORD) { throw new RuntimeException('Hasło administratora musi mieć co najmniej ' . VI_MIN_PASSWORD . ' znaków.'); }
                    if ($p1 !== $p2) { throw new RuntimeException('Hasła administratora nie są takie same.'); }
                    if (strcasecmp($p1, $admin['user']) === 0 || strcasecmp($p1, $db['pass']) === 0) { throw new RuntimeException('Hasło administratora musi być inne niż login i hasło bazy.'); }
                }
                try { $pdo = vi_connect($db); }
                catch (PDOException $e) { throw new RuntimeException('Nie udało się połączyć z bazą: ' . $e->getMessage() . '. Sprawdź dane w panelu hostingu (zwykle host to localhost).'); }
                $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
                $maria = stripos($version, 'mariadb') !== false;
                $num = preg_match('/^(\d+\.\d+)/', $version, $mm) ? $mm[1] : '0';
                if ($maria ? version_compare($num, '10.3', '<') : version_compare($num, '5.7', '<')) {
                    throw new RuntimeException('Za stara wersja bazy (' . $version . '). Potrzebny MySQL 5.7+ lub MariaDB 10.3+.');
                }
                if ($state['phase'] === 'config' && $pdo->query('SHOW TABLES')->fetchAll()) {
                    throw new RuntimeException('Baza ' . $db['name'] . ' nie jest pusta. Użyj nowej, pustej bazy - kreator niczego nie nadpisuje.');
                }
                session_regenerate_id(true);
                $_SESSION['db'] = $db;
                if ($state['phase'] === 'config') {
                    $_SESSION['admin'] = ['user' => $admin['user'], 'email' => $admin['email'], 'hash' => password_hash($p1, PASSWORD_DEFAULT)];
                    $state['phase'] = 'extract';
                    $state['base_url'] = $baseUrl;
                    $state['db_name'] = $db['name'];
                    vi_save_state($stateFile, $state);
                }
                header('Location: ' . $self, true, 303);
                exit;
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }
        if ($state['phase'] !== 'config') {
            $dbOnly = '<form method="post" autocomplete="off">' . vi_hidden() . '<input type="hidden" name="action" value="configure">'
                . '<input type="hidden" name="base_url" value="' . vi_h($baseUrl) . '">'
                . '<label>Host bazy</label><input name="db_host" value="' . vi_h($db['host'] ?? 'localhost') . '" required>'
                . '<label>Port</label><input name="db_port" value="' . vi_h($db['port'] ?? '3306') . '" required>'
                . '<label>Nazwa bazy</label><input name="db_name" value="' . vi_h($db['name'] ?? ($state['db_name'] ?? '')) . '" required>'
                . '<label>Użytkownik bazy</label><input name="db_user" value="' . vi_h($db['user'] ?? '') . '" required>'
                . '<label>Hasło bazy</label><input type="password" name="db_pass"><button type="submit">Kontynuuj</button></form>';
            vi_page('Wznowienie instalacji', '<div class="warn">' . vi_h($error) . '</div>' . $dbOnly, 2);
        }
        vi_page('Baza danych i konto administratora', ($error !== '' ? '<div class="err">' . vi_h($error) . '</div>' : '') . vi_admin_form($db, $admin, $baseUrl, $https), 2);
    }

    $deadline = $started + VI_BUDGET;
    @set_time_limit(120);
    ignore_user_abort(true);

    // 3. Pliki.
    if ($state['phase'] === 'extract') {
        [$zip] = vi_open_package($package);
        $entries = array_keys($manifest['files']);
        $total = count($entries);
        try {
            while ($state['index'] < $total) {
                $entry = (string) $entries[$state['index']];
                if (!vi_safe_entry($entry)) { throw new RuntimeException('Paczka zawiera niebezpieczną ścieżkę: ' . $entry); }
                $target = vi_target($entry, $dir, $self);
                if ($target !== null) {
                    vi_extract($zip, $entry, $manifest['files'][$entry], $target);
                    if ($entry === 'app/.htaccess') { vi_htaccess_block($target, true); }
                }
                $state['index']++;
                if (microtime(true) > $deadline) { break; }
            }
        } finally { $zip->close(); }
        if ($state['index'] >= $total) { $state['phase'] = 'configure'; }
        vi_save_state($stateFile, $state);
        $pct = $total ? (int) floor($state['index'] * 100 / $total) : 100;
        vi_page('Rozpakowywanie plików', '<p>Pliki: ' . $state['index'] . ' z ' . $total . '.</p><progress max="100" value="' . $pct . '"></progress>'
            . '<p class="hint">Nie zamykaj tej karty - strona sama przechodzi dalej.</p>', 3, true);
    }

    // 4. .env, klucz, tabele i konto administratora.
    if ($state['phase'] === 'configure') {
        if (!isset($_SESSION['admin'])) {
            vi_fail('Sesja wygasła przed założeniem konta administratora.', '<p>Usuń plik <code>.veless-install.json</code> przez FTP i otwórz <code>' . vi_h($self) . '</code> ponownie. '
                . 'Pliki zostaną sprawdzone jeszcze raz, baza jest jeszcze pusta.</p>');
        }
        $db = $_SESSION['db']; $baseUrl = (string) $state['base_url'];
        foreach (['storage/sessions', 'storage/backups', 'storage/uploads', 'storage/logs', 'storage/cache', 'storage/security'] as $sub) {
            if (!is_dir($dir . '/' . $sub) && !@mkdir($dir . '/' . $sub, 0700, true) && !is_dir($dir . '/' . $sub)) { throw new RuntimeException('Nie można utworzyć katalogu ' . $sub . '.'); }
        }
        $keyFile = $dir . '/storage/security/totp.key';
        if (!is_file($keyFile)) {
            // Klucz szyfrowania kluczy API i 2FA - poza bazą, więc kopia bazy sama go nie zdradza.
            $old = umask(0077);
            try { if (file_put_contents($keyFile, base64_encode(random_bytes(32))) === false) { throw new RuntimeException('Nie można zapisać klucza szyfrowania.'); } }
            finally { umask($old); }
            @chmod($keyFile, 0600);
        }
        $template = is_file($dir . '/.env.example') ? (string) file_get_contents($dir . '/.env.example') : '';
        if (!is_file($dir . '/.env')) {
            $env = vi_build_env($template, $db, $baseUrl);
            $old = umask(0077);
            try {
                if (file_put_contents($dir . '/.env.vi-part', $env) === false || !rename($dir . '/.env.vi-part', $dir . '/.env')) { throw new RuntimeException('Nie można zapisać pliku .env.'); }
            } finally { umask($old); }
            @chmod($dir . '/.env', 0600);
        }

        // Te same migracje, które system uruchamia przy każdym starcie (config/database.php).
        $config = require $dir . '/config/config.php';
        $pdo = (require $dir . '/config/database.php')($config['db']);
        \Pase\Support\AdminSchema::migrate($pdo);
        if (!$pdo->query("SHOW TABLES LIKE 'settings'")->fetch()) { throw new RuntimeException('Nie udało się założyć tabel. Sprawdź, czy użytkownik bazy ma uprawnienia CREATE i ALTER.'); }
        $a = $_SESSION['admin'];
        $exists = $pdo->prepare('SELECT COUNT(*) FROM admin_users WHERE username = ?');
        $exists->execute([$a['user']]);
        if ((int) $exists->fetchColumn() === 0) {
            $pdo->prepare("INSERT INTO admin_users (username, email, password_hash, display_name, role) VALUES (?, ?, ?, 'Administrator', 'admin')")
                ->execute([$a['user'], $a['email'], $a['hash']]);
        }
        $upsert = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $upsert->execute(['APP_BASE_URL', $baseUrl]);
        $upsert->execute(['ALLEGRO_REDIRECT_URI', $baseUrl . '/auth_allegro_callback.php']);
        $upsert->execute(['INSTALLED_AT', (string) time()]);
        $upsert->execute(['INSTALLED_VERSION', (string) $state['version']]);
        @unlink($dir . '/storage/ADMIN_PASSWORD.txt');
        // Manifest tej wersji: System → Aktualizacja wie z niego, które pliki usunąć przy następnej wersji.
        [$zip] = vi_open_package($package);
        $raw = $zip->getFromName('package.json');
        $zip->close();
        if ($raw !== false && file_put_contents($dir . '/storage/release.json', $raw) !== false) { @chmod($dir . '/storage/release.json', 0600); }
        unset($_SESSION['admin'], $_SESSION['admin_form']);
        $state['phase'] = 'check';
        $state['configured_at'] = time();
        vi_save_state($stateFile, $state);
        header('Location: ' . $self, true, 303);
        exit;
    }

    // 5. Ochrona plików i cron.
    if ($state['phase'] === 'check') {
        $rootUrl = vi_root_url($https);
        $envExposed = vi_exposed($rootUrl . '/.env', 'DB_PASS');
        $keyExposed = vi_exposed($rootUrl . '/storage/security/totp.key', '=');
        $pkgExposed = vi_exposed($rootUrl . '/' . basename($package), 'package.json');
        $exposed = $envExposed === true || $keyExposed === true;
        $unknown = $envExposed === null || $keyExposed === null;

        $config = require $dir . '/config/config.php';
        $pdo = (require $dir . '/config/database.php')($config['db']);
        $lastRun = (int) ($pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'WORKER_LAST_RUN_AT'")->fetchColumn() ?: 0);
        $cronOk = $lastRun >= (int) $state['configured_at'] - 5;

        if ($action === 'finish') {
            if ($exposed) { $state['finish_error'] = 'Najpierw zablokuj dostęp do plików konfiguracji (opis wyżej).'; }
            else { $state['phase'] = 'finalize'; }
            vi_save_state($stateFile, $state);
            header('Location: ' . $self, true, 303);
            exit;
        }
        $php = vi_php_cli();
        $cron = '* * * * * ' . $php . ' ' . $dir . '/cli/worker.php >> ' . $dir . '/storage/cron.log 2>&1';
        $body = '';
        if (!empty($state['finish_error'])) { $body .= '<div class="err">' . vi_h($state['finish_error']) . '</div>'; unset($state['finish_error']); vi_save_state($stateFile, $state); }
        $body .= '<h2>Ochrona plików</h2>';
        if ($exposed) {
            $body .= '<div class="err">Plik <code>.env</code> (hasło bazy) albo klucz szyfrowania można pobrać z internetu. Serwer nie obsługuje pliku .htaccess.</div>'
                . '<p>Ustaw w panelu hostingu <strong>katalog główny domeny (document root)</strong> na <code>' . vi_h($dir . '/public') . '</code> '
                . 'albo poproś pomoc techniczną hostingu o zablokowanie dostępu do katalogu <code>' . vi_h($dir) . '</code> poza podkatalogiem public/. Potem kliknij „Sprawdź ponownie”.</p>';
        } elseif ($unknown) {
            $body .= '<div class="warn">Nie udało się sprawdzić automatycznie (serwer nie łączy się sam ze sobą). Otwórz w nowej karcie '
                . '<code>' . vi_h($rootUrl . '/.env') . '</code> - powinien pojawić się błąd 403 albo 404, a nie tekst z hasłem bazy.</div>';
        } else {
            $body .= '<div class="ok">Plik .env i klucz szyfrowania są zablokowane z internetu.' . ($pkgExposed === false ? ' Paczka też.' : '') . '</div>';
        }
        $body .= '<h2>Cron (zadanie co minutę)</h2>'
            . '<p>Veless pobiera zamówienia, odświeża tokeny i wysyła powiadomienia przez jedno zadanie cron. W panelu hostingu (np. DirectAdmin → „Zadania Cron”, '
            . 'cPanel → „Cron Jobs”) dodaj zadanie uruchamiane <strong>co minutę</strong>:</p><pre>' . vi_h($cron) . '</pre>'
            . '<p class="hint">Jeśli panel pyta osobno o czas, wpisz same gwiazdki (<code>* * * * *</code>), a w polu polecenia resztę linijki. '
            . 'Ścieżka do PHP (<code>' . vi_h($php) . '</code>) musi wskazywać tę samą wersję PHP co strona - hosting podaje ją w opisie zadań cron.</p>'
            . ($cronOk
                ? '<div class="ok">Cron działa - ostatni przebieg ' . vi_h(gmdate('H:i:s', $lastRun)) . ' UTC.</div>'
                : '<div class="warn">Cron jeszcze nie ruszył. Po dodaniu zadania odczekaj minutę i kliknij „Sprawdź ponownie”. Możesz też dokończyć instalację i dodać cron później.</div>')
            . '<form method="get" style="display:inline"><button type="submit" class="sec">Sprawdź ponownie</button></form> '
            . '<form method="post" style="display:inline">' . vi_hidden() . '<input type="hidden" name="action" value="finish"><button type="submit"' . ($exposed ? ' disabled' : '') . '>Zakończ instalację</button></form>';
        vi_page('Bezpieczeństwo i cron', $body, 5);
    }

    // 6. Sprzątanie.
    if ($state['phase'] !== 'finalize') { throw new RuntimeException('Nieznany etap instalacji. Usuń plik .veless-install.json i zacznij od nowa na pustej bazie.'); }
    $baseUrl = (string) $state['base_url'];
    vi_htaccess_block($dir . '/.htaccess', false);
    @unlink($package);
    vi_remove_tree($dir . '/storage/installer');
    @unlink($stateFile);
    @unlink($dir . '/INSTRUKCJA.txt');
    $selfDeleted = @unlink(__FILE__);
    $version = (string) $state['version'];
    $_SESSION = [];
    session_destroy();
    vi_page('Veless ' . $version . ' zainstalowany', '<div class="ok">System jest gotowy. Paczka instalacyjna została usunięta z serwera.</div>'
        . ($selfDeleted ? '' : '<div class="err">Nie udało się usunąć pliku ' . vi_h($self) . ' - usuń go ręcznie przez FTP.</div>')
        . '<p><a href="' . vi_h($baseUrl . '/admin/') . '"><strong>Otwórz panel Veless</strong></a> i zaloguj się kontem administratora, które przed chwilą założyłeś.</p>'
        . '<p><strong>Na początek:</strong></p><ol>'
        . '<li>Konfiguracja → Integracje: podłącz sklep, Allegro i pozostałe kanały.</li>'
        . '<li>Mój profil: włącz logowanie dwuetapowe (aplikacja Google Authenticator).</li>'
        . '<li>Konfiguracja → Synchronizacja: sprawdź, czy cron działa (pasek u góry pokazuje ostatni przebieg).</li>'
        . '</ol>', 6);
} catch (Throwable $e) {
    $msg = $e instanceof PDOException ? 'Błąd bazy danych: ' . $e->getMessage() : $e->getMessage();
    vi_fail($msg, '<p class="hint">Postęp jest zapisany - po usunięciu przyczyny odśwież stronę, a instalacja będzie kontynuowana.</p>');
}
