<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Raport sandboxu wtyczek (docker/sandbox/report.php) na podstawionym przebiegu, bez Dockera:
// token Allegro wysłany do Allegro to zwykłe zapytanie API, ten sam token na inny adres blokuje wtyczkę.
$root = dirname(__DIR__);
$checks = 0;
function ok(bool $condition, string $name): void { global $checks; if (!$condition) { throw new RuntimeException('FAIL: ' . $name); } ++$checks; echo "OK: {$name}\n"; }

$token = 'SBXCANARY-allegro-access-1234567890abcdef';
$run = static function (array $events) use ($root, $token): array {
    $dir = sys_get_temp_dir() . '/crm-sbx-report-' . bin2hex(random_bytes(6));
    mkdir("$dir/net", 0700, true);
    file_put_contents("$dir/scan.json", json_encode(['findings' => [], 'hosts' => ['api.allegro.pl', 'api.allegro.pl.allegrosandbox.pl', 'allegro.pl.evil.example']]));
    file_put_contents("$dir/canaries.json", json_encode([
        'secret' => ['Token Allegro (access)' => $token, 'Hasło SMTP' => 'SBXCANARY-smtp-haslo-abcdef1234567890'],
        'secret_hosts' => ['Token Allegro (access)' => ['allegro.pl', 'allegrosandbox.pl']],
        'own' => [], 'pii' => [],
    ]));
    file_put_contents("$dir/pages-plugin.json", json_encode(['login' => true, 'pages' => []]));
    file_put_contents("$dir/net/network.jsonl", implode("\n", array_map('json_encode', $events)) . "\n");
    file_put_contents("$dir/net-base-start.txt", '0');
    file_put_contents("$dir/net-base-end.txt", '0');
    file_put_contents("$dir/net-plugin-start.txt", '0');
    file_put_contents("$dir/net-plugin-end.txt", (string) count($events));
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/docker/sandbox/report.php") . ' ' . escapeshellarg($dir) . ' allegro integration integrations 2>&1', $out, $code);
    $md = (string) @file_get_contents("$dir/raport.md");
    exec('rm -rf ' . escapeshellarg($dir));
    return [$code, $md];
};
$call = static fn(string $host, string $secret): array => ['proto' => 'https', 'host' => $host, 'method' => 'GET', 'path' => '/me',
    'headers' => ['Authorization' => 'Bearer ' . $secret]];

[$code, $md] = $run([$call('api.allegro.pl.allegrosandbox.pl', $token), $call('api.allegro.pl', $token)]);
ok($code === 0 && !str_contains($md, 'ODRZUCONA') && str_contains($md, 'do usługi, do której ten sekret należy'), 'Allegro token sent to Allegro does not reject the plugin');

foreach (['allegro.pl.evil.example', 'notallegro.pl', 'sklep-testowy.example'] as $host) {
    [$code, $md] = $run([$call($host, $token)]);
    ok($code === 1 && str_contains($md, 'Wysyła sekret „Token Allegro (access)” na zewnątrz.'), "Allegro token sent to {$host} still rejects the plugin");
}
[$code, $md] = $run([$call('api.allegro.pl', 'SBXCANARY-smtp-haslo-abcdef1234567890')]);
ok($code === 1 && str_contains($md, 'Hasło SMTP'), 'Another secret sent to Allegro still rejects the plugin');

echo "plugin_sandbox_report: {$checks} checks passed\n";
