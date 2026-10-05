<?php
declare(strict_types=1);

/**
 * Sonda sandboxu wtyczek - uruchamiana W kontenerze CRM (użytkownik www-data) przez run.sh.
 *
 *   php /sandbox/probe.php seed <slug> <kind>      - fikcyjne dane i „kanarki” (JSON na stdout)
 *   php /sandbox/probe.php exercise <slug> <kind>  - przejście po panelu, zamówienie, automatyzacje,
 *                                                   wywołania wtyczki, przebieg workera (JSON na stdout)
 *
 * Kanarek to unikalna, zmyślona wartość wpisana w miejsce sekretu (token Allegro, klucz wFirma,
 * hasło SMTP, dane klienta). Jeśli pojawi się w ruchu sieciowym albo w pliku - wtyczka go wyniosła.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

const APP = '/var/www/crm';
[$cmd, $slug, $kind] = [$argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? 'integration'];

$config = require APP . '/config/config.php';
$pdo = (require PASE_ROOT . '/config/database.php')($config['db']);

function canary(string $what): string
{
    return 'SBXCANARY-' . $what . '-' . bin2hex(random_bytes(6));
}

/** Wartość pola konfiguracji wtyczki: sekrety to kanarki „własne” (wolno je wysłać do API wtyczki). */
function fieldValue(array $f, array &$own): string
{
    $key = (string) ($f['key'] ?? '');
    $type = (string) ($f['type'] ?? 'text');
    if (!empty($f['secret']) || $type === 'password' || \Pase\Support\SecretStore::isSecretConfigKey($key)) {
        return $own[$key] = canary('wlasny-' . preg_replace('/[^a-z0-9]/i', '', $key));
    }
    return match (true) {
        $type === 'checkbox' => '1',
        $type === 'select' && is_array($f['options'] ?? null) => (string) array_key_first($f['options']),
        $type === 'number' => '1',
        str_contains(strtolower($key), 'url') || str_contains(strtolower($key), 'host') || str_contains(strtolower($key), 'domain') => 'https://sklep-testowy.example',
        str_contains(strtolower($key), 'mail') => 'sklep@sklep-testowy.example',
        default => (string) ($f['default'] ?? 'test'),
    };
}

function http(string $path, ?array $post = null): array
{
    $jar = '/tmp/sbx-probe-cookies.txt';   // raport pomija ten plik (to sonda, nie wtyczka)
    $h = curl_init('http://localhost/' . ltrim($path, '/'));
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '']);
    if ($post !== null) {
        curl_setopt($h, CURLOPT_POST, true);
        curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $t = microtime(true);
    $body = (string) curl_exec($h);
    $res = ['status' => curl_getinfo($h, CURLINFO_RESPONSE_CODE), 'ms' => (int) round((microtime(true) - $t) * 1000),
        'location' => (string) curl_getinfo($h, CURLINFO_REDIRECT_URL), 'body' => $body];
    curl_close($h);
    return $res;
}

function csrf(string $body): string
{
    return preg_match('/name="csrf" value="([a-f0-9]+)"/', $body, $m) ? $m[1] : '';
}

/** Błąd PHP widoczny na stronie (APP_DEBUG) albo pusta odpowiedź 500. */
function pageError(array $r): string
{
    if (preg_match('/(Fatal error|Parse error|Uncaught [A-Za-z\\\\]+)[^<]{0,200}/', $r['body'], $m)) {
        return strip_tags($m[0]);
    }
    if (str_contains($r['body'], 'zgłosiło błąd') || str_contains($r['body'], 'zgłosiła błąd')) {
        return 'Komunikat o błędzie rozszerzenia na stronie';
    }
    return $r['status'] >= 500 ? 'HTTP ' . $r['status'] : '';
}

function run(string $label, callable $fn): array
{
    $t = microtime(true);
    try {
        $out = $fn();
        return ['call' => $label, 'ok' => true, 'ms' => (int) round((microtime(true) - $t) * 1000),
            'result' => mb_substr(json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '', 0, 300)];
    } catch (\Throwable $e) {
        return ['call' => $label, 'ok' => false, 'ms' => (int) round((microtime(true) - $t) * 1000),
            'error' => get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 300)];
    }
}

// ============================================================ seed

if ($cmd === 'seed') {
    // Pierwsze wejście na panel zakłada brakujące tabele i konto admina (jak docker/worker.sh).
    http('admin/login.php');
    $canaries = ['secret' => [], 'secret_hosts' => [], 'own' => [], 'pii' => []];

    // Klucz szyfrowania i szyfrowanie sekretów - jak na prawdziwej instalacji.
    \Pase\Support\SecretStore::sealAll($pdo);

    // Tokeny i klucze innych integracji, ustawienia z sekretami, hasło SMTP.
    $repo = new \Pase\Repository\IntegrationRepository($pdo);
    $repo->saveTokens('allegro', $a = canary('allegro-access'), $b = canary('allegro-refresh'), new DateTimeImmutable('+1 day'));
    $canaries['secret']['Token Allegro (access)'] = $a;
    $canaries['secret']['Token Allegro (refresh)'] = $b;
    // Token Allegro wysłany do samego Allegro to nie wyciek (tak działa każde zapytanie do API) -
    // tylko te domeny (i ich subdomeny); każdy inny adres nadal blokuje.
    $canaries['secret_hosts']['Token Allegro (access)'] = $canaries['secret_hosts']['Token Allegro (refresh)']
        = ['allegro.pl', 'allegrosandbox.pl'];
    $accounts = new \Pase\Repository\IntegrationAccountRepository($pdo);
    $others = [
        'woocommerce' => ['base_url' => 'https://inny-sklep.example', 'consumer_key' => canary('woo-key'), 'consumer_secret' => canary('woo-secret')],
        'wfirma'      => ['access_key' => canary('wfirma-access'), 'secret_key' => canary('wfirma-secret'), 'company_id' => '1'],
        'smsapi'      => ['token' => canary('smsapi-token'), 'sender' => 'Sklep'],
        'payu'        => ['pos_id' => '1', 'client_id' => '1', 'client_secret' => canary('payu-secret'), 'second_key' => canary('payu-md5')],
    ];
    foreach ($others as $type => $cfg) {
        if ($type === $slug) {
            continue;   // wtyczka testowana zamiast wbudowanej - jej konto zakładamy niżej
        }
        $id = $accounts->create($type, 'Konto ' . $type . ' (inne)', $cfg, canary($type . '-webhook'));
        $pdo->prepare('UPDATE integration_accounts SET is_active = 0 WHERE id = ?')->execute([$id]);   // nieaktywne: rdzeń ich nie odpytuje
        foreach ($cfg as $k => $v) {
            if (str_starts_with((string) $v, 'SBXCANARY-')) {
                $canaries['secret']["{$type}: {$k}"] = $v;
            }
        }
    }
    foreach ($pdo->query("SELECT webhook_secret FROM integration_accounts") as $row) {
        $plain = \Pase\Support\SecretStore::reveal((string) $row['webhook_secret'], 'integration_accounts.webhook_secret');
        if ($plain !== null && str_starts_with($plain, 'SBXCANARY-')) {
            $canaries['secret']['Sekret webhooka ' . explode('-', $plain)[1]] = $plain;
        }
    }
    $settings = ['WFIRMA_SECRET_KEY' => canary('ustawienie-wfirma'), 'WOO_CONSUMER_SECRET' => canary('ustawienie-woo'),
        'GUS_API_KEY' => canary('ustawienie-gus')];
    (new \Pase\Repository\SettingsRepository($pdo))->setMany($settings);
    foreach ($settings as $k => $v) {
        $canaries['secret']["Ustawienie {$k}"] = $v;
    }
    try {
        (new \Pase\Services\MailAccounts($pdo))->save(null, ['name' => 'Sklep', 'from_email' => 'sklep@sklep-testowy.example',
            'from_name' => 'Sklep', 'host' => 'smtp.sklep-testowy.example', 'port' => '587', 'user' => 'sklep',
            'pass' => $p = canary('smtp-haslo'), 'secure' => 'none']);
        $canaries['secret']['Hasło SMTP'] = $p;
    } catch (\Throwable) {
    }

    // Fikcyjni klienci: ich dane też są „kanarkami” (dane osobowe).
    $svc = new \Pase\Services\ManualOrders($pdo, new \Pase\Queue\Queue($pdo));
    for ($i = 1; $i <= 3; $i++) {
        $mail = 'sbxklient' . bin2hex(random_bytes(4)) . '@example.com';
        $phone = '+48 5' . random_int(10, 99) . ' ' . random_int(100, 999) . ' ' . random_int(100, 999);
        $last = 'Kanarkowska' . strtoupper(bin2hex(random_bytes(3)));
        $checked = $svc->validate(['billing_first_name' => 'Anna', 'billing_last_name' => $last, 'billing_email' => $mail,
            'billing_phone' => $phone, 'billing_address_1' => 'ul. Testowa ' . $i, 'billing_postcode' => '00-00' . $i,
            'billing_city' => 'Warszawa', 'billing_country' => 'PL', 'item_name' => ['Świeca sojowa'], 'item_price' => ['49.90'],
            'item_qty' => [(string) $i], 'shipping_method' => 'Kurier', 'shipping_cost' => '14.99', 'payment_method' => 'bacs',
            'customer_note' => 'Proszę zapakować na prezent', 'paid' => $i === 1 ? '1' : '']);
        if ($checked['errors'] === []) {
            $svc->create($checked['order'], 'sandbox');
            $canaries['pii']["E-mail klienta {$i}"] = $mail;
            $canaries['pii']["Nazwisko klienta {$i}"] = $last;
        }
    }

    // Konto testowanej integracji: pola z manifestu, sekrety = kanarki własne.
    if ($kind === 'integration') {
        \Pase\Plugin\PluginRegistry::reset();
        $plugin = \Pase\Plugin\PluginRegistry::all()[$slug] ?? null;
        if ($plugin === null) {
            // Wtyczka jest jeszcze wyłączona (.disabled) - wczytujemy ją wprost, tak jak instalator.
            $class = require APP . "/integrations/{$slug}/register.php";
            $plugin = is_string($class) && class_exists($class) ? new $class() : null;
        }
        if ($plugin !== null) {
            $own = [];
            $cfg = [];
            foreach ($plugin->manifest()->fields as $f) {
                if (isset($f['key'])) {
                    $cfg[$f['key']] = fieldValue($f, $own);
                }
            }
            $accountId = $accounts->create($slug, 'Konto testowe', $cfg);
            $canaries['own'] = $own;
            $canaries['account_id'] = $accountId;
        }
    }

    $env = (string) file_get_contents(APP . '/.env');
    foreach (['DB_PASS', 'WORKER_HTTP_SECRET', 'ADMIN_DEFAULT_PASSWORD'] as $k) {
        if (preg_match('/^' . $k . '=(.+)$/m', $env, $m)) {
            $canaries['secret']["Plik .env: {$k}"] = trim($m[1]);
        }
    }
    $key = trim((string) @file_get_contents(APP . '/storage/security/totp.key'));
    if ($key !== '') {
        $canaries['secret']['Klucz szyfrowania (totp.key)'] = $key;
    }
    echo json_encode($canaries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
    exit;
}

// ============================================================ exercise

if ($cmd !== 'exercise') {
    fwrite(STDERR, "Nieznane polecenie\n");
    exit(2);
}

$env = (string) file_get_contents(APP . '/.env');
preg_match('/^ADMIN_DEFAULT_PASSWORD=(.+)$/m', $env, $m);
$login = http('admin/login.php');
$r = http('admin/login.php', ['csrf' => csrf($login['body']), 'username' => 'admin', 'password' => trim($m[1] ?? '')]);
$report = ['login' => in_array($r['status'], [302, 303], true), 'pages' => [], 'calls' => []];

$orderIds = array_map('intval', $pdo->query('SELECT woo_order_id FROM woo_orders ORDER BY woo_order_id LIMIT 3')->fetchAll(PDO::FETCH_COLUMN));
$pages = ['admin/index.php', 'admin/dashboard.php', 'admin/orders.php', 'admin/customers.php', 'admin/products.php',
    'admin/statistics.php', 'admin/automations.php', 'admin/plugins.php', 'admin/integrations.php', 'admin/packing.php',
    'admin/queue.php', 'admin/statuses.php', 'admin/notifications.php', 'admin/returns.php', 'admin/email_templates.php',
    'admin/sync_settings.php', 'admin/users.php', 'admin/security.php', 'admin/help.php', 'pack/index.php'];
foreach ($orderIds as $id) {
    $pages[] = "admin/order_view.php?id={$id}";
}
// Strony dodane przez rozszerzenia (Hooks::addPage) - widoczne tylko, gdy wtyczka jest włączona.
if ($kind === 'extension') {
    foreach (array_keys(\Pase\Plugin\Hooks::pages()) as $key) {
        $pages[] = 'admin/' . \Pase\Plugin\Hooks::pageUrl($key);
    }
}
if (isset($argv[4]) && ctype_digit($argv[4])) {
    $pages[] = "admin/integration_edit.php?id={$argv[4]}";
    $pages[] = "admin/integration_edit.php?id={$argv[4]}&action=test";
}
foreach ($pages as $p) {
    $res = http($p);
    $report['pages'][$p] = ['status' => $res['status'], 'ms' => $res['ms'], 'error' => pageError($res), 'bytes' => strlen($res['body'])];
}

// Nowe zamówienie z automatyzacjami (zdarzenie order.imported) i zmiana statusu (status.changed).
$form = http('admin/order_new.php');
$res = http('admin/order_new.php', ['csrf' => csrf($form['body']), 'billing_first_name' => 'Jan', 'billing_last_name' => 'Sandboxowy',
    'billing_email' => 'jan.sandbox@example.com', 'billing_address_1' => 'ul. Próbna 1', 'billing_postcode' => '00-001',
    'billing_city' => 'Kraków', 'billing_country' => 'PL', 'item_name' => ['Wosk'], 'item_price' => ['19.90'], 'item_qty' => ['1'],
    'payment_method' => 'bacs', 'run_automations' => '1']);
$report['pages']['POST admin/order_new.php'] = ['status' => $res['status'], 'ms' => $res['ms'], 'error' => pageError($res), 'bytes' => strlen($res['body'])];
if ($orderIds !== []) {
    $view = http("admin/order_view.php?id={$orderIds[0]}");
    $res = http("admin/order_view.php?id={$orderIds[0]}", ['csrf' => csrf($view['body']), 'action' => 'set_status', 'pase_status' => 'processing']);
    $report['pages']['POST admin/order_view.php (status)'] = ['status' => $res['status'], 'ms' => $res['ms'], 'error' => pageError($res), 'bytes' => strlen($res['body'])];
}

// Wywołania samej integracji na koncie testowym (fałszywe klucze, „internet” to sink).
if ($kind === 'integration' && isset($argv[4]) && is_file(APP . "/integrations/{$slug}/register.php") && !is_file(APP . "/integrations/{$slug}/.disabled")) {
    \Pase\Plugin\PluginRegistry::reset();
    $acc = (new \Pase\Repository\IntegrationAccountRepository($pdo))->find((int) $argv[4]);
    $plugin = $acc !== null ? \Pase\Plugin\PluginRegistry::forAccount($slug, $acc['config']) : null;
    if ($plugin !== null) {
        $report['calls'][] = run('testConnection()', static fn() => $plugin->testConnection());
        if ($plugin instanceof \Pase\Plugin\Contract\OrderSource) {
            $report['calls'][] = run('fetchOrders(1, 5)', static fn() => $plugin->fetchOrders(1, 5));
        }
        if ($plugin instanceof \Pase\Plugin\Contract\Warehouse) {
            $report['calls'][] = run('fetchProducts(1, 5)', static fn() => $plugin->fetchProducts(1, 5));
        }
        if ($plugin instanceof \Pase\Plugin\Contract\Sms) {
            $report['calls'][] = run('sendSms()', static fn() => $plugin->sendSms('+48500000000', 'Test sandboxu'));
        }
        if ($plugin instanceof \Pase\Plugin\Contract\Courier) {
            $report['calls'][] = run('listServices()', static fn() => $plugin->listServices(['weight' => 1, 'width' => 10, 'height' => 10, 'length' => 10]));
        }
        if ($plugin instanceof \Pase\Plugin\Contract\Invoicing) {
            $report['calls'][] = run('issueDocument()', static fn() => $plugin->issueDocument(['name' => 'Jan Sandboxowy', 'country' => 'PL'],
                [['name' => 'Wosk', 'quantity' => 1, 'price' => 19.9, 'vat' => '23']], 'normal', []));
        }
    } else {
        $report['calls'][] = ['call' => 'PluginRegistry::forAccount()', 'ok' => false, 'error' => 'Rejestr nie rozpoznał wtyczki'];
    }
}

// Przebieg workera, jak cron co minutę.
$t = microtime(true);
exec('timeout 120 php ' . escapeshellarg(APP . '/cli/worker.php') . ' 2>&1', $lines, $code);
$report['worker'] = ['exit' => $code, 'ms' => (int) round((microtime(true) - $t) * 1000), 'output' => mb_substr(implode("\n", $lines), -1500)];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
