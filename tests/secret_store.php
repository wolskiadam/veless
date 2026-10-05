<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Szyfrowanie sekretów w bazie (Pase\Support\SecretStore) na SQLite, bez sieci i bez produkcyjnych kluczy.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php';
    }
});

use Pase\Repository\IntegrationAccountRepository;
use Pase\Repository\IntegrationRepository;
use Pase\Repository\SettingsRepository;
use Pase\Support\SecretStore;

$checks = 0;
function verify(bool $ok, string $name): void {
    global $checks;
    if (!$ok) { throw new RuntimeException('FAIL: ' . $name); }
    ++$checks; echo 'OK: ' . $name . "\n";
}
function rejects(callable $fn, string $name): void {
    try { $fn(); } catch (Throwable $e) { verify(true, $name); return; }
    verify(false, $name);
}

$dir = sys_get_temp_dir() . '/crm-secret-store-' . bin2hex(random_bytes(4));
mkdir($dir, 0700);
$keyFile = $dir . '/totp.key';

function freshDb(): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('CREATE TABLE integrations (id INTEGER PRIMARY KEY, platform_name TEXT UNIQUE, access_token TEXT, refresh_token TEXT, expires_at TEXT, webhook_secret TEXT)');
    $pdo->exec('CREATE TABLE integration_accounts (id INTEGER PRIMARY KEY, type TEXT, name TEXT, is_active INTEGER, config TEXT, webhook_secret TEXT, access_token TEXT, refresh_token TEXT, expires_at TEXT)');
    $pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
    $pdo->exec('CREATE TABLE mail_accounts (id INTEGER PRIMARY KEY, name TEXT, pass TEXT, logo BLOB)');
    $pdo->exec('CREATE TABLE admin_users (id INTEGER PRIMARY KEY, totp_secret TEXT)');
    return $pdo;
}

try {
    // --- Bez klucza: zapis jak dotąd (jawny), nic się nie blokuje ---
    SecretStore::useKeyFile($keyFile);
    verify(!SecretStore::available(), 'No key file means no encryption key');
    verify(SecretStore::seal('abc', 'x') === 'abc', 'Without key value stays plain (no outage)');

    // --- sealAll tworzy klucz na czystej bazie i szyfruje istniejące jawne wartości ---
    $pdo = freshDb();
    $pdo->exec("INSERT INTO integrations (platform_name, access_token, refresh_token) VALUES ('allegro', 'AT-plain', 'RT-plain')");
    $pdo->exec("INSERT INTO integration_accounts (type, name, is_active, config, access_token) VALUES ('smsapi', 'SMS', 1, '{\"token\":\"sms-secret\",\"sender\":\"Sklep\"}', 'tt-access')");
    $pdo->exec("INSERT INTO settings VALUES ('WFIRMA_SECRET_KEY', 'wf-secret'), ('APP_BASE_URL', 'https://crm.example')");
    $pdo->exec("INSERT INTO mail_accounts (name, pass, logo) VALUES ('Sklep', 'smtp-pass', X'89504E47')");
    verify(SecretStore::sealAll($pdo) === 4, 'sealAll encrypts every row holding plain secrets');
    verify(is_file($keyFile) && SecretStore::available(), 'sealAll created the key on a database without encrypted data');
    verify((fileperms($keyFile) & 0777) === 0600, 'Key file is private (0600)');

    $raw = $pdo->query("SELECT access_token, refresh_token FROM integrations")->fetch();
    verify(SecretStore::isSealed($raw['access_token']) && SecretStore::isSealed($raw['refresh_token']), 'Allegro tokens are ciphertext in the database');
    verify(!str_contains($raw['access_token'], 'AT-plain'), 'Ciphertext does not contain the token');
    $cfg = json_decode((string) $pdo->query('SELECT config FROM integration_accounts')->fetchColumn(), true);
    verify(SecretStore::isSealed($cfg['token']) && $cfg['sender'] === 'Sklep', 'Only secret config fields are encrypted');
    verify(SecretStore::isSealed($pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'WFIRMA_SECRET_KEY'")->fetchColumn()), 'Secret setting encrypted');
    verify($pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'APP_BASE_URL'")->fetchColumn() === 'https://crm.example', 'Ordinary setting untouched');
    verify(SecretStore::isSealed($pdo->query('SELECT pass FROM mail_accounts')->fetchColumn()), 'SMTP password encrypted');
    verify($pdo->query('SELECT hex(logo) FROM mail_accounts')->fetchColumn() === '89504E47', 'Other columns of the row untouched');
    verify(SecretStore::sealAll($pdo) === 0, 'Second sealAll changes nothing');

    // --- Repozytoria czytają jawnie i zapisują zaszyfrowane ---
    $integrations = new IntegrationRepository($pdo);
    verify($integrations->find('allegro')['access_token'] === 'AT-plain', 'IntegrationRepository decrypts on read');
    $accounts = new IntegrationAccountRepository($pdo);
    $acc = $accounts->find(1);
    verify($acc['config']['token'] === 'sms-secret' && $acc['access_token'] === 'tt-access', 'IntegrationAccountRepository decrypts config and tokens');
    $accounts->saveTokens(1, 'new-access', 'new-refresh', new DateTimeImmutable('2030-01-01'));
    $raw = $pdo->query('SELECT access_token, refresh_token FROM integration_accounts WHERE id = 1')->fetch();
    verify(SecretStore::isSealed($raw['access_token']) && SecretStore::isSealed($raw['refresh_token']), 'saveTokens stores ciphertext');
    verify($accounts->find(1)['refresh_token'] === 'new-refresh', 'Saved token reads back');
    $id = $accounts->create('wfirma', 'wFirma', ['access_key' => 'ak', 'secret_key' => 'sk', 'company_id' => '42'], 'hook');
    $raw = $pdo->query("SELECT config, webhook_secret FROM integration_accounts WHERE id = {$id}")->fetch();
    $cfg = json_decode($raw['config'], true);
    verify(SecretStore::isSealed($cfg['access_key']) && SecretStore::isSealed($cfg['secret_key']) && $cfg['company_id'] === '42', 'create() encrypts secret fields');
    verify(SecretStore::isSealed($raw['webhook_secret']), 'Webhook secret encrypted');
    verify($accounts->activeByType('wfirma')[0]['config']['secret_key'] === 'sk' && $accounts->all()[1]['webhook_secret'] === 'hook', 'activeByType/all decrypt');
    $settings = new SettingsRepository($pdo);
    $settings->setMany(['WOO_CONSUMER_SECRET' => 'cs', 'WOO_BASE_URL' => 'https://shop.example']);
    verify(SecretStore::isSealed($pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'WOO_CONSUMER_SECRET'")->fetchColumn()), 'setMany encrypts secret settings');
    verify($settings->get('WOO_CONSUMER_SECRET') === 'cs' && $settings->get('WOO_BASE_URL') === 'https://shop.example', 'SettingsRepository decrypts');

    // --- Wartości są związane z polem: podmiana między kolumnami nie przejdzie ---
    $sealed = SecretStore::seal('x', 'integrations.access_token');
    rejects(static fn() => SecretStore::open($sealed, 'integrations.refresh_token'), 'Ciphertext moved to another field is rejected');
    rejects(static fn() => SecretStore::open(substr($sealed, 0, -4) . 'AAAA', 'integrations.access_token'), 'Tampered ciphertext is rejected');
    verify(SecretStore::open('legacy-plain', 'x') === 'legacy-plain', 'Plain legacy values still readable');

    // --- Kopie sekcji: archiwum ma wartości jawne, przywrócenie szyfruje kluczem tej instalacji ---
    $row = $pdo->query('SELECT * FROM integration_accounts WHERE id = 1')->fetch();
    $open = SecretStore::openRow('integration_accounts', $row);
    verify($open['access_token'] === 'new-access' && json_decode($open['config'], true)['token'] === 'sms-secret', 'openRow decrypts for export');
    $back = SecretStore::sealRow('integration_accounts', $open);
    verify(SecretStore::isSealed($back['access_token']) && SecretStore::isSealed(json_decode($back['config'], true)['token']), 'sealRow re-encrypts on restore');

    // --- Utrata klucza: strona działa (pusta wartość), ścisły odczyt mówi, co zrobić ---
    SecretStore::useKeyFile($dir . '/missing.key');
    verify($integrations->find('allegro')['access_token'] === '', 'Missing key does not crash reads');
    rejects(static fn() => SecretStore::open($sealed, 'integrations.access_token'), 'Strict open without key fails');
    verify(SecretStore::sealAll($pdo) === 0 && !is_file($dir . '/missing.key'), 'No new key when encrypted data exists');

    // --- Klucza nie tworzymy, gdy istnieją dane 2FA (nowy klucz by je unieważnił) ---
    $pdo2 = freshDb();
    $pdo2->exec("INSERT INTO admin_users (totp_secret) VALUES ('v1:xyz')");
    $pdo2->exec("INSERT INTO settings VALUES ('WFIRMA_SECRET_KEY', 'plain')");
    SecretStore::useKeyFile($dir . '/other.key');
    verify(SecretStore::sealAll($pdo2) === 0 && !is_file($dir . '/other.key'), 'No key created when 2FA secrets exist');
    verify((new SettingsRepository($pdo2))->get('WFIRMA_SECRET_KEY') === 'plain', 'Plain value keeps working without key');
} finally {
    SecretStore::useKeyFile(null);
    foreach (glob($dir . '/*') ?: [] as $f) { unlink($f); }
    rmdir($dir);
}

echo "Secret store checks passed: {$checks}\n";
