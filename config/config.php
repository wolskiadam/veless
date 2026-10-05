<?php
declare(strict_types=1);

/**
 * Centralny bootstrap: ładuje .env, rejestruje autoloader PSR-4 (bez Composera,
 * żeby działało na shared hostingu nawet bez `composer install`).
 * Każdy plik wejściowy (webhook / cli) zaczyna od:  require config/bootstrap.
 */

use Pase\Support\Env;

define('PASE_ROOT', dirname(__DIR__));

// --- Autoloader PSR-4 dla namespace Pase\ -> src/ ---
spl_autoload_register(static function (string $class): void {
    $prefix = 'Pase\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = PASE_ROOT . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

// --- Autoloader wtyczek: PasePlugin\<Vendor>\... -> integrations/<vendor>/... ---
// Wtyczki integracji żyją w katalogu integrations/ (jak wtyczki WP w wp-content).
// Każda używa własnego podnamespace pod PasePlugin\, np.
// PasePlugin\Blpaczka\BlpaczkaPlugin -> integrations/blpaczka/BlpaczkaPlugin.php.
// (Plik wejściowy register.php zwraca FQCN klasy.) Nie kolidują z rdzeniem.
spl_autoload_register(static function (string $class): void {
    $prefix = 'PasePlugin\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    // Pierwszy segment (vendor) -> małe litery (nazwa katalogu); reszta bez zmian.
    $parts  = explode('\\', $relative);
    $parts[0] = strtolower($parts[0]);
    $path = PASE_ROOT . '/integrations/' . implode('/', $parts) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

// --- Wczytaj konfigurację .env ---
Env::load(PASE_ROOT . '/.env');

// --- Wersja demonstracyjna (DEMO_MODE=1): odrzuć pliki i zablokowane strony, zanim cokolwiek się wykona ---
\Pase\Support\Demo::guardRequest();

// --- Globalny skrót tłumaczeń: t('klucz', ['n' => 1]) ---
if (!function_exists('t')) {
    /** @param array<string,string|int|float> $vars */
    function t(string $key, array $vars = []): string
    {
        return \Pase\Support\I18n::t($key, $vars);
    }
}

// --- Strefa czasowa: wszystko liczymy w UTC, formatujemy lokalnie w razie potrzeby ---
date_default_timezone_set('UTC');

// --- Licznik obciążenia (System → Obciążenie serwera): czas i pamięć każdego żądania ---
if (class_exists(\Pase\Support\UsageStats::class)) {
    \Pase\Support\UsageStats::boot();
}

// --- Ustawienia z bazy (tabela settings) mają pierwszeństwo nad .env ---
// Klucze API edytowane w panelu zapisują się do bazy. Tu wczytujemy je lekkim,
// osobnym połączeniem (config.php jest ładowany przed głównym PDO, więc nie
// możemy użyć database.php - byłaby rekurencja). Jeśli baza niedostępna lub
// tabela nie istnieje, po cichu zostajemy przy .env.
$dbSettings = [];
try {
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        Env::get('DB_HOST', 'localhost'),
        Env::int('DB_PORT', 3306),
        Env::require('DB_NAME'),
        Env::get('DB_CHARSET', 'utf8mb4')
    );
    $settingsPdo = new PDO($dsn, Env::require('DB_USER'), Env::get('DB_PASS', ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $dbSettings = $settingsPdo->query('SELECT setting_key, setting_value FROM settings')
        ->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    $settingsPdo = null; // zwolnij; główne PDO powstanie w database.php
    // Klucze API w bazie są szyfrowane (Pase\Support\SecretStore) - tu odszyfrowane do konfiguracji.
    foreach ($dbSettings as $k => $v) {
        $dbSettings[$k] = \Pase\Support\SecretStore::openSetting((string) $k, $v, false);
    }
} catch (\Throwable $e) {
    $dbSettings = []; // brak bazy/tabeli -> fallback na .env
}

// Helper: wartość z bazy (priorytet) lub z .env (fallback).
$cfg = static function (string $dbKey, string $envKey, string $default = '') use ($dbSettings): string {
    $fromDb = $dbSettings[$dbKey] ?? '';
    if ($fromDb !== '') {
        return $fromDb;
    }
    return Env::get($envKey, $default) ?? $default;
};

// User-Agent wymagany przez Allegro API na KAŻDE zapytanie (inaczej ryzyko zablokowania
// klucza) - format "Nazwa/Wersja (+URL)". Edytowalne w panelu (Marketplace → Allegro →
// Ustawienia), patrz allegro_settings.php.
$allegroAppName    = $cfg('ALLEGRO_APP_NAME', 'ALLEGRO_APP_NAME', 'Veless');
$allegroAppVersion = $cfg('ALLEGRO_APP_VERSION', 'ALLEGRO_APP_VERSION', '1.0');
$allegroAppUrl     = $cfg('ALLEGRO_APP_URL', 'ALLEGRO_APP_URL', '');
$allegroUserAgent  = trim($allegroAppName) . '/' . trim($allegroAppVersion)
    . ($allegroAppUrl !== '' ? ' (+' . trim($allegroAppUrl) . ')' : '');

// --- Zwróć płaską konfigurację (wygodne przy serwisach) ---
$paseConfig = [
    'app' => [
        'env'           => Env::get('APP_ENV', 'production'),
        'debug'         => Env::bool('APP_DEBUG', false),
        'worker_secret' => Env::get('WORKER_HTTP_SECRET', ''),
    ],
    'db' => [
        'host'    => Env::get('DB_HOST', 'localhost'),
        'port'    => Env::int('DB_PORT', 3306),
        'name'    => Env::require('DB_NAME'),
        'user'    => Env::require('DB_USER'),
        'pass'    => Env::get('DB_PASS', ''),
        'charset' => Env::get('DB_CHARSET', 'utf8mb4'),
    ],
    'allegro' => [
        'client_id'     => $cfg('ALLEGRO_CLIENT_ID', 'ALLEGRO_CLIENT_ID'),
        'client_secret' => $cfg('ALLEGRO_CLIENT_SECRET', 'ALLEGRO_CLIENT_SECRET'),
        'redirect_uri'  => $cfg('ALLEGRO_REDIRECT_URI', 'ALLEGRO_REDIRECT_URI'),
        'env'           => $cfg('ALLEGRO_ENV', 'ALLEGRO_ENV', 'sandbox'),
        'user_agent'    => $allegroUserAgent,
    ],
    'woo' => [
        'base_url'        => rtrim($cfg('WOO_BASE_URL', 'WOO_BASE_URL'), '/'),
        'consumer_key'    => $cfg('WOO_CONSUMER_KEY', 'WOO_CONSUMER_KEY'),
        'consumer_secret' => $cfg('WOO_CONSUMER_SECRET', 'WOO_CONSUMER_SECRET'),
        'webhook_secret'  => $cfg('WOO_WEBHOOK_SECRET', 'WOO_WEBHOOK_SECRET'),
        // Czy zmiana statusu w PASE ma być odsyłana do WooCommerce (przełącznik w panelu).
        'sync_status'     => $cfg('WOO_SYNC_STATUS', 'WOO_SYNC_STATUS') === '1',
    ],
    'wfirma' => [
        'base_url'   => rtrim($cfg('WFIRMA_BASE_URL', 'WFIRMA_BASE_URL', 'https://api2.wfirma.pl'), '/'),
        'access_key' => $cfg('WFIRMA_ACCESS_KEY', 'WFIRMA_ACCESS_KEY'),
        'secret_key' => $cfg('WFIRMA_SECRET_KEY', 'WFIRMA_SECRET_KEY'),
        'app_key'    => $cfg('WFIRMA_APP_KEY', 'WFIRMA_APP_KEY'),
        'company_id' => $cfg('WFIRMA_COMPANY_ID', 'WFIRMA_COMPANY_ID'),
    ],
    'worker' => [
        'batch_size'            => Env::int('WORKER_BATCH_SIZE', 50),
        'allegro_min_interval'  => Env::int('ALLEGRO_MIN_INTERVAL_MS', 350),
    ],
];

// Wspólny kontekst dla wtyczek (patrz Pase\Support\Runtime).
\Pase\Support\Runtime::setConfig($paseConfig);

return $paseConfig;
