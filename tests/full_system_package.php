<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Pełna kopia systemu: budowa pakietu + instalacja przez przeglądarkę (php -S) do NOWEJ bazy.
// Explicit opt-in. Only loopback server, random new databases; never loads .env or application config.
if (getenv('CRM_TEST_MYSQL') !== '1') {
    fwrite(STDERR, "NOT RUN: set CRM_TEST_MYSQL=1 and dedicated local test MySQL credentials.\n");
    exit(2);
}
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php';
    }
});
use Pase\Services\FullSystemPackage;
use Pase\Support\Migrator;

$checks = 0;
function check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    ++$checks; echo "OK: $label\n";
}
$port = (int) (getenv('CRM_TEST_MYSQL_PORT') ?: 3306);
$user = getenv('CRM_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('CRM_TEST_MYSQL_PASSWORD') ?: '';
$server = new PDO('mysql:host=127.0.0.1;port=' . $port . ';charset=utf8mb4', $user, $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$source = 'crm_test_' . bin2hex(random_bytes(12));
$target = 'crm_test_' . bin2hex(random_bytes(12));
$temp = sys_get_temp_dir() . '/crm-full-' . bin2hex(random_bytes(8));
$proc = null;
$rm = static function (string $path) use (&$rm): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) { return; }
    foreach (scandir($path) as $e) { if ($e !== '.' && $e !== '..') { $rm($path . '/' . $e); } }
    rmdir($path);
};
try {
    foreach ([$source, $target] as $db) { $server->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4"); }
    $pdo = new PDO('mysql:host=127.0.0.1;port=' . $port . ';dbname=' . $source . ';charset=utf8mb4', $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    (new Migrator($pdo))->migrate();
    $old = 'https://twojsklep.example/pase/public';
    $pdo->exec("DELETE FROM settings");
    $ins = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)');
    $ins->execute(['APP_BASE_URL', $old]);
    $ins->execute(['CLIENT_LINK_BASE', $old . '/order.php']);
    $ins->execute(['SHOP_URL', 'https://sklep.example']);
    $pdo->exec('DELETE FROM integration_accounts');
    $acc = $pdo->prepare('INSERT INTO integration_accounts (type, name, config) VALUES (?, ?, ?)');
    $acc->execute(['allegro', 'Allegro', json_encode(['client_id' => 'x', 'redirect_uri' => $old . '/auth_allegro_callback.php', 'env' => 'production'])]);
    $acc->execute(['woocommerce', 'Sklep', json_encode(['base_url' => 'https://sklep.example', 'consumer_key' => 'ck'])]);
    $pdo->exec("CREATE TABLE crm_probe (id INT NOT NULL PRIMARY KEY, bin VARBINARY(16) NULL, ts TIMESTAMP NULL, txt TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $probe = $pdo->prepare('INSERT INTO crm_probe (id, bin, ts, txt) VALUES (?, ?, ?, ?)');
    $pdo->exec("SET time_zone = '+00:00'");
    $probe->execute([0, "\x00\xff\x10", '2026-01-02 03:04:05', 'Zażółć gęślą jaźń 🕯']);
    for ($i = 1; $i <= 450; ++$i) { $probe->execute([$i, null, null, str_repeat('x', $i)]); }
    $pdo->exec("SET time_zone = '+05:00'"); // aplikacja może mieć inną strefę sesji - kopia i tak musi być w UTC

    // Fikcyjny katalog aplikacji.
    $root = $temp . '/app'; $external = $temp . '/private';
    foreach (['/src/Installer', '/config', '/public/admin', '/storage/uploads', '/storage/sessions', '/storage/backups/sections', '/storage/security', '/.git', '/tests'] as $d) { mkdir($root . $d, 0700, true); }
    mkdir($external, 0700);
    copy(dirname(__DIR__) . '/src/Installer/install.php', $root . '/src/Installer/install.php');
    file_put_contents($root . '/config/config.php', "<?php // app\n");
    file_put_contents($root . '/public/admin/index.php', "<?php echo 'panel';\n");
    file_put_contents($root . '/.htaccess', "Options -Indexes\n");
    file_put_contents($root . '/storage/uploads/photo.png', random_bytes(3000));
    file_put_contents($root . '/storage/sessions/sess_abc', 'secret-session');
    file_put_contents($root . '/storage/backups/sections/old.zip', 'old');
    file_put_contents($root . '/storage/app.log', 'log');
    file_put_contents($root . '/storage/security/login-limit.json', '{}');
    file_put_contents($root . '/.git/HEAD', 'ref');
    file_put_contents($root . '/tests/t.php', '<?php');
    symlink($root . '/config', $root . '/linked');
    $totp = random_bytes(32);
    file_put_contents($external . '/totp.key', $totp);
    file_put_contents($root . '/.env', "APP_ENV=production\nDB_HOST=127.0.0.1\nDB_NAME=$source\nDB_USER=old\nDB_PASS=\"old pass\"\n"
        . "ALLEGRO_REDIRECT_URI=$old/auth_allegro_callback.php\nTOTP_KEY_FILE=$external/totp.key\nBACKUP_DIR=/old/backups\nWOO_BASE_URL=https://sklep.example\n");

    $full = new FullSystemPackage($pdo, $root, '1.2.3');
    $threw = false;
    try { $full->build('short'); } catch (RuntimeException) { $threw = true; }
    check($threw, 'Package requires a password of at least 12 characters');
    $password = 'haslo-kopii-123';
    $name = $full->build($password, $old);
    check((string) $pdo->query('SELECT @@session.time_zone')->fetchColumn() === '+05:00', 'Build restores the session time zone');
    check($full->list()[0]['file'] === $name && glob($full->dir() . '/stage-*') === [], 'Package listed; no plaintext staging left behind');
    $outer = new ZipArchive(); $outer->open($full->path($name));
    $names = []; for ($i = 0; $i < $outer->numFiles; ++$i) { $names[] = $outer->getNameIndex($i); }
    sort($names);
    check(count($names) === 4 && in_array('install.php', $names, true) && in_array('.htaccess', $names, true) && in_array('PRZENIESIENIE.txt', $names, true)
        && preg_grep('/^crm-pakiet-[a-f0-9]{32}\.zip$/', $names), 'Download contains installer, encrypted package, instructions and .htaccess');
    $guide = (string) $outer->getFromName('PRZENIESIENIE.txt'); $outer->close();
    check(str_contains($guide, $old . '/auth_allegro_callback.php') && str_contains($guide, '{NOWY_ADRES}/auth_allegro_callback.php')
        && preg_match('#\{NOWY_ADRES\}/webhook_woo_order\.php\?integration=\d+#', $guide) && str_contains($guide, $root . '/cli/worker.php')
        && str_contains($guide, '{NOWY_KATALOG}/cli/worker.php') && str_contains($guide, 'SSL'),
        'PRZENIESIENIE.txt lists old cron, Allegro redirect, Woo webhooks and new-address placeholders');
    check(!str_contains($guide, 'old pass') && !str_contains($guide, '"ck"') && !str_contains($guide, 'consumer') && !str_contains($guide, $password)
        && !preg_match('/client_id|consumer_|secret|refresh_token|access_token/i', $guide), 'PRZENIESIENIE.txt holds no passwords, keys or tokens');
    check(str_contains($full->guide($old), '{NOWY_ADRES}/webhook_woo_order.php'), 'Guide can be generated separately (download next to the backup)');
    $outer->open($full->path($name));
    $web = $temp . '/web/crm'; mkdir($web, 0755, true);
    $outer->extractTo($web); $outer->close();
    $pkg = glob($web . '/crm-pakiet-*.zip')[0];
    $inner = new ZipArchive(); $inner->open($pkg);
    $innerNames = []; $allAes = true;
    for ($i = 0; $i < $inner->numFiles; ++$i) { $s = $inner->statIndex($i); $innerNames[] = $s['name']; $allAes = $allAes && $s['encryption_method'] === ZipArchive::EM_AES_256; }
    check($allAes && $inner->getFromName('package.json') === false, 'Every package entry is AES-256 encrypted');
    $inner->close();
    check(in_array('app/.env', $innerNames, true) && in_array('app/storage/uploads/photo.png', $innerNames, true) && in_array('secrets/totp.key', $innerNames, true),
        'Package holds .env, uploads and the external 2FA key');
    check(!preg_grep('#storage/(sessions|backups)/|\.log$|security/.*\.json$|^app/\.git/|^app/tests/|^app/linked#', $innerNames), 'Sessions, other backups, logs, limiter state, git, tests and symlinks are left out');
    $full->delete($name);
    check($full->list() === [], 'Package can be deleted');

    // Instalacja przez przeglądarkę: php -S, budżet czasu ~0, żeby każdy etap musiał się wznawiać.
    $httpPort = 18000 + random_int(0, 999);
    $proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $httpPort, '-t', $temp . '/web'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', $temp . '/server.log', 'w']], $pipes, null,
        ['CRM_INSTALLER_BUDGET' => '0.02', 'PATH' => getenv('PATH') ?: '/usr/bin']);
    $url = 'http://127.0.0.1:' . $httpPort . '/crm/install.php';
    for ($i = 0; $i < 50 && !@file_get_contents('http://127.0.0.1:' . $httpPort . '/'); ++$i) { usleep(100000); }
    $cookies = '';
    $http = static function (array $post = []) use (&$cookies, $url): string {
        $ctx = stream_context_create(['http' => ['method' => $post ? 'POST' : 'GET', 'ignore_errors' => true, 'follow_location' => 0,
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nCookie: $cookies\r\n", 'content' => http_build_query($post)]]);
        $body = (string) file_get_contents($url, false, $ctx);
        foreach ($http_response_header as $line) {
            if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $line, $m)) { $cookies = $m[1]; }
        }
        return $body;
    };
    $csrf = static fn(string $html): string => preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $m) ? $m[1] : '';
    $page = $http();
    check(str_contains($page, 'Hasło kopii'), 'Installer asks for the package password first');
    $page = $http(['csrf' => $csrf($page), 'action' => 'unlock', 'password' => 'zle-haslo-kopii']);
    check(str_contains($page, 'Nieprawidłowe hasło') && (int) file_get_contents($web . '/.crm-install-attempts') === 1, 'Wrong password is rejected and counted');
    $http(['csrf' => $csrf($page), 'action' => 'unlock', 'password' => $password]);
    $page = $http();
    check(str_contains($page, 'Dane nowej bazy') && str_contains($page, 'http://127.0.0.1:' . $httpPort . '/crm/public'), 'Correct password shows the database form with the detected address');
    $newBase = 'https://nowa-domena.example/crm/public';
    $form = ['action' => 'configure', 'db_host' => '127.0.0.1', 'db_port' => (string) $port, 'db_user' => $user, 'db_pass' => $pass, 'base_url' => $newBase];
    $page = $http(['csrf' => $csrf($page), 'db_name' => $source] + $form);
    check(str_contains($page, 'nie jest pusta') && !is_dir($web . '/config'), 'Installer refuses a non-empty database and writes nothing');
    $http(['csrf' => $csrf($page), 'db_name' => $target] + $form);
    $steps = 0; $lostSession = false;
    $page = $http();
    while (++$steps < 5000) {
        if (str_contains($page, 'CRM zainstalowany') || str_contains($page, 'class="err"')) { break; }
        if (!$lostSession && str_contains($page, 'Import bazy danych')) {
            // Przerwana sesja w połowie importu: hasło i dane bazy jeszcze raz, import idzie dalej.
            $lostSession = true; $cookies = '';
            $page = $http();
            $http(['csrf' => $csrf($page), 'action' => 'unlock', 'password' => $password]);
            $page = $http();
            check(str_contains($page, 'Wznowienie instalacji'), 'Lost session resumes after password and database details');
            $http(['csrf' => $csrf($page), 'db_name' => $target] + $form);
            $page = $http();
            continue;
        }
        $page = $http(['csrf' => $csrf($page), 'action' => 'continue']);
    }
    if (!str_contains($page, 'CRM zainstalowany')) { echo substr(strip_tags($page), 0, 600), "\n"; }
    check(str_contains($page, 'CRM zainstalowany'), 'Installation finishes (' . $steps . ' resumed steps)' . (str_contains($page, 'class="err"') ? ': ' . strip_tags($page) : ''));
    check(str_contains($page, $web . '/cli/worker.php') && str_contains($page, $newBase . '/auth_allegro_callback.php')
        && str_contains($page, $newBase . '/webhook_woo_order.php?integration='), 'Final page lists cron, Allegro redirect and Woo webhook values');
    check(!is_file($web . '/install.php') && glob($web . '/crm-pakiet-*') === [] && !is_dir($web . '/storage/installer')
        && !is_file($web . '/.crm-install-state.json') && !is_file($web . '/PRZENIESIENIE.txt'), 'Installer removes itself, the package and its work files');
    check(file_get_contents($web . '/.htaccess') === "Options -Indexes\n", 'Application .htaccess restored without the installer block');
    check(file_get_contents($web . '/storage/uploads/photo.png') === file_get_contents($root . '/storage/uploads/photo.png')
        && file_get_contents($web . '/storage/security/totp.key') === $totp && (fileperms($web . '/storage/security/totp.key') & 0077) === 0,
        'Uploads restored and the external 2FA key moved to storage/security (0600)');
    $env = file_get_contents($web . '/.env');
    check(str_contains($env, "DB_NAME=\"$target\"") && str_contains($env, 'DB_HOST="127.0.0.1"') && !str_contains($env, 'old pass')
        && str_contains($env, 'ALLEGRO_REDIRECT_URI="' . $newBase . '/auth_allegro_callback.php"')
        && preg_match('/^# TOTP_KEY_FILE=/m', $env) && preg_match('/^# BACKUP_DIR=/m', $env) && str_contains($env, 'WOO_BASE_URL=https://sklep.example'),
        'New .env: new database, new redirect, old server paths disabled, other keys kept');
    check((fileperms($web . '/.env') & 0077) === 0, '.env is private (0600)');
    $new = new PDO('mysql:host=127.0.0.1;port=' . $port . ';dbname=' . $target . ';charset=utf8mb4', $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec("SET time_zone = '+00:00'"); $new->exec("SET time_zone = '+00:00'");
    $srcTables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    check($srcTables === $new->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN), 'All tables recreated');
    $same = true;
    foreach ($srcTables as $t) {
        if (in_array($t, ['settings', 'integration_accounts'], true)) { continue; }
        $same = $same && $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn() == $new->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    }
    check($same && (int) $new->query('SELECT COUNT(*) FROM crm_probe')->fetchColumn() === 451, 'Row counts match');
    $row = $new->query('SELECT * FROM crm_probe WHERE id = 0')->fetch();
    check($row !== false && $row['bin'] === "\x00\xff\x10" && $row['ts'] === '2026-01-02 03:04:05' && $row['txt'] === 'Zażółć gęślą jaźń 🕯',
        'Binary, TIMESTAMP (UTC), Unicode and id 0 survive');
    $settings = $new->query('SELECT setting_key, setting_value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    check($settings['APP_BASE_URL'] === $newBase && $settings['CLIENT_LINK_BASE'] === $newBase . '/order.php'
        && $settings['ALLEGRO_REDIRECT_URI'] === $newBase . '/auth_allegro_callback.php' && $settings['SHOP_URL'] === 'https://sklep.example',
        'Stored panel address replaced in settings; unrelated URLs untouched');
    $cfg = json_decode((string) $new->query("SELECT config FROM integration_accounts WHERE type = 'allegro'")->fetchColumn(), true);
    check($cfg['redirect_uri'] === $newBase . '/auth_allegro_callback.php' && $cfg['client_id'] === 'x', 'Allegro integration redirect updated');
    check(!is_file($web . '/storage/sessions/sess_abc') && !is_file($web . '/.git/HEAD'), 'No sessions or git data copied');
    check(!preg_match('/(Warning|Notice|Deprecated|Fatal)/', (string) file_get_contents($temp . '/server.log')), 'Installer ran without PHP warnings');
    echo "PASS: $checks checks\n";
} finally {
    if (is_file($temp . '/server.log') && preg_match('/(Warning|Notice|Deprecated|Fatal)/', (string) file_get_contents($temp . '/server.log'))) { echo file_get_contents($temp . '/server.log'); }
    if ($proc) { proc_terminate($proc); proc_close($proc); }
    foreach ([$source, $target] as $db) { $server->exec("DROP DATABASE IF EXISTS `$db`"); }
    $rm($temp);
}
