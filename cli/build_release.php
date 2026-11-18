<?php
declare(strict_types=1);

/**
 * Paczka wydania Veless do instalacji przez kreator w przeglądarce (jak WordPress).
 *
 *   php cli/build_release.php [--version=1.0.170] [--out=dist] [--commit=<sha>] [--update-url=https://…/] [--notes="…"]
 *
 * Podpis: klucz prywatny Ed25519 (base64) w zmiennej środowiskowej VELESS_SIGNING_KEY albo w pliku --key-file.
 * Bez klucza paczka jest niepodpisana (da się ją wgrać ręcznie, ale aktualizacja z serwera jej nie przyjmie).
 * --update-url: adres serwera aktualizacji (updates-server/), zapisany w config/build.json - zainstalowany
 * system będzie tam raz dziennie sprawdzał nowe wersje.
 *
 * Wynik w katalogu --out (domyślnie dist/):
 *   veless-<wersja>.zip              - kod systemu (package.json z sumami SHA-256 + pliki w app/),
 *   instaluj.php                     - kreator (src/Installer/setup.php),
 *   INSTRUKCJA.txt                   - trzy kroki dla użytkownika,
 *   veless-<wersja>-instalacja.zip   - powyższe trzy pliki razem, do pobrania przez subskrybenta.
 *
 * Do paczki trafia to samo co przy wdrożeniu FTP (bez testów, Dockera, strony www, dokumentacji .md),
 * a z katalogu storage/ tylko pliki .htaccess blokujące dostęp z internetu. Nigdy .env ani dane.
 */

if (PHP_SAPI !== 'cli') {
    exit('Uruchom z linii poleceń: php cli/build_release.php');
}

$root = dirname(__DIR__);
$opts = getopt('', ['version:', 'out:', 'commit:', 'update-url:', 'key-file:', 'notes:']);

function release_fail(string $msg): never
{
    fwrite(STDERR, "BŁĄD: {$msg}\n");
    exit(1);
}

if (!class_exists(ZipArchive::class)) {
    release_fail('Brak rozszerzenia PHP zip.');
}

// --- Wersja: VERSION z AppVersion + numer kompilacji (GitHub Actions albo liczba commitów) ---
$version = (string) ($opts['version'] ?? '');
if ($version === '') {
    preg_match("/const VERSION\s*=\s*'([0-9.]+)'/", (string) file_get_contents($root . '/src/Support/AppVersion.php'), $m);
    $major = $m[1] ?? '1.0';
    $build = (string) (getenv('GITHUB_RUN_NUMBER') ?: trim((string) @shell_exec('git -C ' . escapeshellarg($root) . ' rev-list --count HEAD 2>/dev/null')));
    $version = $major . '.' . ($build !== '' && ctype_digit($build) ? $build : '0');
}
if (!preg_match('/^\d+(\.\d+){1,3}$/D', $version)) {
    release_fail('Wersja musi mieć postać liczb rozdzielonych kropkami, np. 1.0.170.');
}
$commit = (string) ($opts['commit'] ?? (getenv('GITHUB_SHA') ?: trim((string) @shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse HEAD 2>/dev/null'))));
$updateUrl = trim((string) ($opts['update-url'] ?? (getenv('VELESS_UPDATE_URL') ?: '')));
if ($updateUrl !== '' && !preg_match('#^https://[^\s"]+$#', $updateUrl)) {
    release_fail('--update-url musi być adresem https://');
}
$signingKey = isset($opts['key-file']) ? trim((string) @file_get_contents((string) $opts['key-file'])) : trim((string) getenv('VELESS_SIGNING_KEY'));
if ($signingKey !== '' && !function_exists('sodium_crypto_sign_detached')) {
    release_fail('Do podpisu potrzebne jest rozszerzenie PHP sodium.');
}
$out = (string) ($opts['out'] ?? ($root . '/dist'));
if (!str_starts_with($out, '/')) {
    $out = getcwd() . '/' . $out;
}

// --- Co pomijamy (jak w wdrożeniu FTP w veless-awien/.github/workflows/deploy.yml) ---
const RELEASE_SKIP_TOP = ['.git', '.github', 'tests', 'mockup', 'agent-app', '_to_delete_tmp_stage', 'examples', 'docker', 'site', 'updates-server',
    'dane', 'dist', 'storage', 'Dockerfile', 'docker-compose.yml', '.dockerignore', '.gitignore', '.gitattributes', '.push-request',
    '.push-result', 'pase.code-workspace', 'vendor', 'node_modules'];
const RELEASE_SKIP_ANY = ['.git', '.DS_Store', '__pycache__', 'node_modules', 'Thumbs.db'];
/** Z storage/ tylko blokady dostępu z internetu i pusty znacznik katalogu. */
const RELEASE_STORAGE_KEEP = ['storage/.htaccess', 'storage/.gitkeep', 'storage/security/.htaccess'];

function release_included(string $rel): bool
{
    $parts = explode('/', $rel);
    if (in_array($parts[0], RELEASE_SKIP_TOP, true)) {
        return in_array($rel, RELEASE_STORAGE_KEEP, true);
    }
    foreach ($parts as $p) {
        if (in_array($p, RELEASE_SKIP_ANY, true)) {
            return false;
        }
    }
    $base = end($parts);
    if ($base === '.env' || (str_starts_with($base, '.env.') && $rel !== '.env.example')) {
        return false;
    }
    if (str_ends_with($base, '.md') && !in_array($rel, ['integrations/README.md', 'extensions/README.md'], true)) {
        return false;
    }
    if ($rel === 'config/build.json' || str_ends_with($base, '.pyc') || str_ends_with($base, '.swp')) {
        return false;
    }
    return true;
}

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($it as $f) {
    /** @var SplFileInfo $f */
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
    if ($f->isLink() || !$f->isFile()) {
        continue;
    }
    if (release_included($rel)) {
        $files[] = $rel;
    }
}
foreach (RELEASE_STORAGE_KEEP as $keep) {
    if (is_file($root . '/' . $keep) && !in_array($keep, $files, true)) {
        $files[] = $keep;
    }
}
sort($files, SORT_STRING);
foreach (['config/config.php', 'public/admin/index.php', 'cli/worker.php', '.env.example', '.htaccess', 'src/Installer/setup.php'] as $must) {
    if (!in_array($must, $files, true)) {
        release_fail("W paczce brakuje {$must}.");
    }
}

if (!is_dir($out) && !mkdir($out, 0755, true)) {
    release_fail("Nie można utworzyć katalogu {$out}.");
}

// --- Znacznik wersji w paczce: AppVersion pokaże w stopce dokładnie ten numer ---
$builtAt = time();
$stamp = ['id' => 'release-' . $version, 'deployed_at' => $builtAt, 'commit' => $commit, 'version' => $version];
if ($updateUrl !== '') { $stamp['update_url'] = $updateUrl; }
$buildJson = json_encode($stamp, JSON_UNESCAPED_SLASHES) . "\n";

$manifest = ['format' => 'veless-release-v1', 'name' => 'Veless', 'version' => $version, 'commit' => $commit,
    'created_at' => gmdate('c', $builtAt), 'php_min' => '8.1.0', 'notes' => trim((string) ($opts['notes'] ?? '')), 'files' => []];
foreach ($files as $rel) {
    $manifest['files']['app/' . $rel] = ['size' => filesize($root . '/' . $rel), 'sha256' => hash_file('sha256', $root . '/' . $rel)];
}
$manifest['files']['app/config/build.json'] = ['size' => strlen($buildJson), 'sha256' => hash('sha256', $buildJson)];
ksort($manifest['files'], SORT_STRING);

$pkgName = 'veless-' . $version . '.zip';
$pkgPath = $out . '/' . $pkgName;
@unlink($pkgPath);
$zip = new ZipArchive();
if ($zip->open($pkgPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
    release_fail("Nie można utworzyć {$pkgPath}.");
}
$manifestRaw = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
$zip->addFromString('package.json', $manifestRaw);
if ($signingKey !== '') {
    $sk = base64_decode($signingKey, true);
    if ($sk === false || strlen($sk) !== 64) { release_fail('VELESS_SIGNING_KEY nie jest kluczem prywatnym Ed25519 (64 bajty w base64).'); }
    $zip->addFromString('package.sig', base64_encode(sodium_crypto_sign_detached($manifestRaw, $sk)));
}
$stored = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'zip', 'gz', 'pdf', 'woff', 'woff2', 'ico'];
foreach ($files as $rel) {
    $zip->addFile($root . '/' . $rel, 'app/' . $rel);
    if (in_array(strtolower(pathinfo($rel, PATHINFO_EXTENSION)), $stored, true)) {
        $zip->setCompressionName('app/' . $rel, ZipArchive::CM_STORE);
    }
}
$zip->addFromString('app/config/build.json', $buildJson);
if (!$zip->close()) {
    release_fail('Nie udało się zapisać paczki.');
}

copy($root . '/src/Installer/setup.php', $out . '/instaluj.php');
$guide = <<<TXT
Veless {$version} - instalacja na hostingu

1. W panelu hostingu utwórz pustą bazę MySQL lub MariaDB (zapisz nazwę bazy, użytkownika i hasło)
   i ustaw PHP w wersji 8.1 lub nowszej.
2. Wgraj przez FTP albo menedżer plików DWA pliki do katalogu, w którym ma działać system
   (np. public_html/veless/):
       instaluj.php
       {$pkgName}
   Nie rozpakowuj paczki - zrobi to kreator.
3. Otwórz w przeglądarce adres tego katalogu z dopiskiem /instaluj.php,
   np. https://twoja-domena.pl/veless/instaluj.php, i przejdź przez kreator.

Na końcu kreator pokaże linijkę do wklejenia w zadaniach cron (panel hostingu) i sam usunie
siebie oraz paczkę z serwera.

TXT;
file_put_contents($out . '/INSTRUKCJA.txt', $guide);

$bundlePath = $out . '/veless-' . $version . '-instalacja.zip';
@unlink($bundlePath);
$bundle = new ZipArchive();
if ($bundle->open($bundlePath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
    release_fail("Nie można utworzyć {$bundlePath}.");
}
$bundle->addFile($out . '/instaluj.php', 'instaluj.php');
$bundle->addFile($pkgPath, $pkgName);
$bundle->setCompressionName($pkgName, ZipArchive::CM_STORE);
$bundle->addFile($out . '/INSTRUKCJA.txt', 'INSTRUKCJA.txt');
if (!$bundle->close()) {
    release_fail('Nie udało się zapisać paczki do pobrania.');
}

fwrite(STDOUT, sprintf("Veless %s: %d plików, paczka %s (%.1f MB), %s\n", $version, count($files) + 1, $pkgName, filesize($pkgPath) / 1048576,
    $signingKey !== '' ? 'podpisana' : 'BEZ PODPISU'));
fwrite(STDOUT, "Do pobrania: {$bundlePath}\n");
exit(0);
