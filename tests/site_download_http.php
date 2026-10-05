<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Strona open source (site/): zapis e-maila, podpisany link do pobrania, wypis i eksport adresów.
$root = dirname(__DIR__);
$checks = 0;
function ok(bool $condition, string $name): void { global $checks; if (!$condition) { throw new RuntimeException('FAIL: ' . $name); } ++$checks; echo "OK: {$name}\n"; }

$dir = sys_get_temp_dir() . '/site-test-' . bin2hex(random_bytes(4));
mkdir($dir);
foreach (['index.html', 'lib.php', 'download.php', 'unsubscribe.php', 'export.php', 'config.example.php'] as $f) {
    copy("{$root}/site/{$f}", "{$dir}/{$f}");
}
file_put_contents("{$dir}/package.zip", "PK-fake-zip");
file_put_contents("{$dir}/config.php", '<?php return ' . var_export([
    'secret' => 'test-secret-0123456789', 'export_key' => 'export-key-42', 'download_file' => "{$dir}/package.zip",
    'download_name' => 'veless.zip', 'rate_limit' => 5, 'site_url' => '',
], true) . ';');

$port = random_int(20000, 40000);
$base = "http://127.0.0.1:{$port}/";
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $dir], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
register_shutdown_function(static function () use ($proc, $dir): void {
    proc_terminate($proc);
    foreach (glob("{$dir}/{,data/,data/.}*", GLOB_BRACE) as $f) { if (is_file($f)) { unlink($f); } }
    @rmdir("{$dir}/data");
    @rmdir($dir);
});
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) { usleep(100000); }

function req(string $path, ?array $post = null): array {
    global $base;
    $h = curl_init($base . $path);
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 10]);
    if ($post !== null) { curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $res = (string) curl_exec($h);
    $len = curl_getinfo($h, CURLINFO_HEADER_SIZE);
    preg_match('/^Location: (.+)$/mi', substr($res, 0, $len), $m);
    return ['status' => curl_getinfo($h, CURLINFO_RESPONSE_CODE), 'location' => trim($m[1] ?? ''), 'body' => substr($res, $len)];
}
function csv_lines(string $file): array { global $dir; return is_file("{$dir}/data/{$file}") ? file("{$dir}/data/{$file}", FILE_IGNORE_NEW_LINES) : []; }

$r = req('download.php', ['email' => 'nie-email', 'consent' => '1']);
ok($r['status'] === 303 && str_contains($r['location'], 'blad=email'), 'bad e-mail goes back with error');
$r = req('download.php', ['email' => 'jan@example.com']);
ok($r['status'] === 303 && str_contains($r['location'], 'blad=zgoda'), 'missing consent goes back with error');
$r = req('download.php', ['email' => 'bot@example.com', 'consent' => '1', 'website' => 'http://spam']);
ok($r['status'] === 200 && !str_contains($r['body'], 'download.php?t='), 'honeypot gets no link');
ok(csv_lines('subscribers.csv') === [], 'nothing saved for rejected and bot sign-ups');

$r = req('download.php', ['email' => 'Jan@Example.com', 'consent' => '1', 'website' => '']);
ok($r['status'] === 200 && preg_match('/download\.php\?t=([A-Za-z0-9_.-]+)/', $r['body'], $m) === 1, 'valid sign-up shows download link');
$token = $m[1];
$rows = csv_lines('subscribers.csv');
ok(count($rows) === 1 && str_contains($rows[0], 'Jan@Example.com') && str_contains($rows[0], 'zgoda-v1'), 'sign-up saved with consent version');
ok(!str_contains($rows[0], '127.0.0.1'), 'raw IP not stored');

$r = req('download.php?t=' . $token);
ok($r['status'] === 200 && $r['body'] === 'PK-fake-zip', 'signed link downloads the package');
$r = req('download.php?t=' . substr($token, 0, -2) . 'xx');
ok($r['status'] === 303 && str_contains($r['location'], 'blad=link'), 'tampered link rejected');
$r = req('download.php');
ok($r['status'] === 303 && str_contains($r['location'], 'blad=link'), 'download without link rejected');

$r = req('export.php?key=wrong');
ok($r['status'] === 403, 'export needs the key');
$r = req('export.php?key=export-key-42');
ok($r['status'] === 200 && str_contains($r['body'], 'jan@example.com') && preg_match('#unsubscribe\.php\?e=[^,\s]+#', $r['body'], $u) === 1, 'export lists address with unsubscribe link');

$r = req('unsubscribe.php?e=jan%40example.com&s=bad');
ok($r['status'] === 400, 'unsubscribe with bad signature rejected');
$r = req($u[0]);
ok($r['status'] === 200 && str_contains($r['body'], 'Wypisano'), 'unsubscribe link works');
$r = req('export.php?key=export-key-42');
ok(!str_contains($r['body'], 'jan@example.com'), 'unsubscribed address left out of export');

for ($i = 0; $i < 5; $i++) { $last = req('download.php', ['email' => "u{$i}@example.com", 'consent' => '1']); }
ok($last['status'] === 303 && str_contains($last['location'], 'blad=limit'), 'per-IP rate limit');

echo "site_download_http: {$checks} checks passed\n";
