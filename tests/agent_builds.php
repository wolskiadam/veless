<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Program Veless (drukowanie) na serwerze: manifest z GitHub Actions, wydawanie plików, kod połączenia.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Services\AgentBuilds;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

$root = sys_get_temp_dir() . '/crm-agent-builds-' . bin2hex(random_bytes(4));
mkdir($root . '/storage/agent-builds', 0777, true);
$builds = new AgentBuilds($root);

check($builds->manifest() === null && $builds->build('windows') === null, 'No builds before the first upload');

file_put_contents($root . '/storage/agent-builds/Veless-windows.exe', 'EXE-BYTES');
file_put_contents($root . '/storage/agent-builds/manifest.json', json_encode([
    'version' => '2.0.1',
    'files' => [
        'windows' => ['file' => 'Veless-windows.exe', 'sha256' => strtoupper(hash('sha256', 'EXE-BYTES')), 'size' => 9],
        'macos' => ['file' => 'Veless-macos.zip', 'sha256' => 'x', 'size' => 1],
        'linux' => ['file' => '../../config/config.php', 'sha256' => 'x', 'size' => 1],
    ],
]));
$win = $builds->build('windows');
check($win !== null && $win['version'] === '2.0.1' && $win['size'] === 9, 'Windows build read from the manifest');
check($win['sha256'] === hash('sha256', 'EXE-BYTES'), 'Checksum returned in lowercase (agent compares hexdigest)');
check($builds->build('macos') === null, 'Listed but missing file is not offered');
check($builds->build('linux') === null && $builds->build('../windows') === null, 'Unknown platform or path traversal rejected');

file_put_contents($root . '/storage/agent-builds/manifest.json', '{broken');
check($builds->manifest() === null && $builds->build('windows') === null, 'Broken manifest ignored');

// Kod połączenia musi dać się odczytać w programie (pase_agent.config.parse_connection_code).
$code = AgentBuilds::connectionCode('https://twojsklep.pl/pase/public/', 'k3y/+=?ąć');
check(str_starts_with($code, 'CRM1:') && !preg_match('/[+\/=]/', substr($code, 5)), 'Connection code is URL-safe base64');
$py = trim((string) shell_exec('command -v python3'));
if ($py !== '') {
    $script = 'import sys; sys.path.insert(0, sys.argv[1]); from pase_agent.config import parse_connection_code as p; import json; print(json.dumps(p(sys.argv[2])))';
    $out = shell_exec(escapeshellarg($py) . ' -c ' . escapeshellarg($script) . ' ' . escapeshellarg(dirname(__DIR__) . '/agent-app') . ' ' . escapeshellarg($code));
    $parsed = json_decode((string) $out, true);
    check(is_array($parsed) && in_array('https://twojsklep.pl/pase/public', $parsed, true) && in_array('k3y/+=?ąć', $parsed, true), 'Program reads the panel connection code');
}

// Strona pobierania jest w uprawnieniach „Drukowanie”.
$src = file_get_contents(dirname(__DIR__) . '/src/Support/PagePermissions.php');
check(str_contains($src, "'agent_app_download.php'"), 'Download page covered by page permissions');
foreach (['public/agent_update.php', 'public/admin/agent_app_download.php'] as $f) {
    exec('php -l ' . escapeshellarg(dirname(__DIR__) . '/' . $f), $o, $rc);
    check($rc === 0, "$f parses");
}
check(str_contains(file_get_contents(dirname(__DIR__) . '/public/agent_update.php'), 'hash_equals'), 'Update endpoint requires the agent API key');

exec('rm -rf ' . escapeshellarg($root));
echo "\nAll $checks agent build checks passed.\n";
