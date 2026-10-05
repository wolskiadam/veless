<?php
declare(strict_types=1);

// Wspólne funkcje strony pobierania: konfiguracja, podpisy, zapis adresów.

function site_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $file = __DIR__ . '/config.php';
        $cfg = (is_file($file) ? require $file : []) + (require __DIR__ . '/config.example.php');
        $cfg['data_dir'] = $cfg['data_dir'] ?? __DIR__ . '/data';
    }
    return $cfg;
}

function site_secret(): string
{
    $s = (string) site_config()['secret'];
    if ($s === '' || str_starts_with($s, 'ZMIEN')) {
        throw new RuntimeException('Ustaw "secret" w config.php.');
    }
    return $s;
}

function b64url(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64url_decode(string $s): string { return (string) base64_decode(strtr($s, '-_', '+/'), true); }

function sign(string $data): string { return b64url(hash_hmac('sha256', $data, site_secret(), true)); }

/** Link do pobrania: ważny do $exp, związany z adresem e-mail. */
function download_token(string $email, int $exp): string
{
    $data = $exp . '.' . b64url(hash('sha256', strtolower($email), true));
    return b64url($data) . '.' . sign('dl|' . $data);
}

function verify_download_token(string $token): bool
{
    $parts = explode('.', $token);
    if (count($parts) !== 2) { return false; }
    $data = b64url_decode($parts[0]);
    if ($data === '' || !hash_equals(sign('dl|' . $data), $parts[1])) { return false; }
    return (int) explode('.', $data)[0] >= time();
}

function unsubscribe_sig(string $email): string { return sign('unsub|' . strtolower($email)); }

function ip_hash(): string { return substr(hash_hmac('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? ''), site_secret()), 0, 16); }

/** Dopisuje wiersz CSV z blokadą pliku. */
function append_csv(string $name, array $row): void
{
    $dir = site_config()['data_dir'];
    if (!is_dir($dir)) { mkdir($dir, 0750, true); }
    $fh = fopen($dir . '/' . $name, 'ab');
    if ($fh === false) { throw new RuntimeException('Nie można zapisać ' . $name); }
    flock($fh, LOCK_EX);
    fputcsv($fh, $row, ',', '"', '');
    flock($fh, LOCK_UN);
    fclose($fh);
}

/** @return array<int,array<int,string>> */
function read_csv(string $name): array
{
    $file = site_config()['data_dir'] . '/' . $name;
    if (!is_file($file)) { return []; }
    $rows = [];
    $fh = fopen($file, 'rb');
    flock($fh, LOCK_SH);
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) { if ($r !== [null]) { $rows[] = $r; } }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $rows;
}

/** Limit prób z jednego IP na godzinę (plik z licznikami). */
function rate_limited(): bool
{
    $limit = (int) site_config()['rate_limit'];
    if ($limit <= 0) { return false; }
    $dir = site_config()['data_dir'];
    if (!is_dir($dir)) { mkdir($dir, 0750, true); }
    $fh = fopen($dir . '/rate.json', 'c+b');
    flock($fh, LOCK_EX);
    $all = json_decode((string) stream_get_contents($fh), true) ?: [];
    $now = time();
    foreach ($all as $k => $times) {
        $all[$k] = array_values(array_filter($times, static fn($t) => $t > $now - 3600));
        if ($all[$k] === []) { unset($all[$k]); }
    }
    $key = ip_hash();
    $hit = count($all[$key] ?? []) >= $limit;
    if (!$hit) { $all[$key][] = $now; }
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, (string) json_encode($all));
    flock($fh, LOCK_UN);
    fclose($fh);
    return $hit;
}

function site_url(string $path): string
{
    $base = rtrim((string) site_config()['site_url'], '/');
    if ($base === '') {
        $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        $base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir;
    }
    return $base . '/' . ltrim($path, '/');
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function back_with_error(string $code): never
{
    header('Location: index.html?blad=' . $code . '#pobierz', true, 303);
    exit;
}

/** Prosta strona z komunikatem w stylu witryny. */
function page(string $title, string $html): never
{
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    echo '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . h($title) . ' – Veless</title><style>'
        . ':root{--bg:#f6f5f2;--line:#e6e3da;--ink:#1f1d1a;--muted:#6b665d;--accent:#9c6b2e;--accent-hover:#835a24;--accent-soft:#f2e6d0}'
        . 'body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;background:var(--bg);color:var(--ink);line-height:1.55}'
        . '.box{max-width:560px;margin:10vh auto;background:#fff;border:1px solid var(--line);border-radius:18px;padding:36px 32px;box-shadow:0 8px 30px rgba(31,29,26,.07)}'
        . 'h1{margin:0 0 12px;font-size:28px}p{color:var(--muted)}a{color:#5a3c14}'
        . '.btn{display:inline-block;background:var(--accent);color:#fff;text-decoration:none;font-weight:700;border-radius:10px;padding:13px 22px;margin:8px 0}'
        . '.btn:hover{background:var(--accent-hover)}code{background:var(--accent-soft);padding:2px 6px;border-radius:6px}'
        . '@media (max-width:600px){.box{margin:24px 16px;padding:26px 20px}}'
        . '</style></head><body><div class="box">' . $html . '</div></body></html>';
    exit;
}
