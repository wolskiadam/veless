<?php
declare(strict_types=1);

/**
 * Powiadomienia (webhooki) do wtyczek integracji: /webhook_plugin.php?a=<id konta z integration_accounts>.
 *
 * Rdzeń tylko znajduje aktywne konto i jego wtyczkę, a całą resztę (autoryzacja, treść, odpowiedź) robi
 * metoda wtyczki handleWebhook(array $request, PDO $pdo): array{status:int, body:string}.
 * Pierwszy użytkownik: Uniwersalny Pusher ORLEN Paczka (zmiany statusów paczek, integrations/orlenpaczka).
 *
 * Błąd po naszej stronie (np. baza niedostępna) -> kod inny niż 200, żeby nadawca ponowił powiadomienie.
 */

use Pase\Repository\IntegrationAccountRepository;
use Pase\Support\Logger;

$config = require dirname(__DIR__) . '/config/config.php';
Logger::toFile(PASE_ROOT . '/storage/app.log');
header('Content-Type: text/plain; charset=utf-8');

$respond = static function (int $status, string $body): void {
    http_response_code($status);
    echo $body;
};

$accountId = (int) ($_GET['a'] ?? 0);
if ($accountId <= 0) {
    $respond(404, 'unknown integration');
    return;
}

try {
    $pdo = (require PASE_ROOT . '/config/database.php')($config['db']);
    \Pase\Services\Handover::exitWhenAway($pdo); // praca przeniesiona na drugą instalację - ta strona nic nie robi
    $account = (new IntegrationAccountRepository($pdo))->find($accountId);
} catch (\Throwable $e) {
    Logger::warn('Webhook wtyczki: brak bazy - ' . $e->getMessage(), ['account' => $accountId]);
    $respond(400, 'temporary error');
    return;
}

$plugin = $account !== null && !empty($account['is_active'])
    ? \Pase\Plugin\PluginRegistry::forAccount((string) $account['type'], $account['config'] ?? [])
    : null;
if ($plugin === null || !method_exists($plugin, 'handleWebhook')) {
    $respond(404, 'unknown integration');
    return;
}

// Nagłówki: Authorization bywa ukryty przez serwer (CGI/FPM) - szukamy go też w REDIRECT_HTTP_AUTHORIZATION i getallheaders().
$headers = [];
foreach ($_SERVER as $k => $v) {
    if (is_string($v) && str_starts_with($k, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
    }
}
if (!isset($headers['authorization']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $headers['authorization'] = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
}
if (!isset($headers['authorization']) && function_exists('getallheaders')) {
    foreach ((array) getallheaders() as $k => $v) {
        if (strtolower((string) $k) === 'authorization') {
            $headers['authorization'] = (string) $v;
        }
    }
}
if (!isset($headers['authorization']) && isset($_SERVER['PHP_AUTH_USER'])) {
    $headers['authorization'] = 'Basic ' . base64_encode($_SERVER['PHP_AUTH_USER'] . ':' . ($_SERVER['PHP_AUTH_PW'] ?? ''));
}

try {
    $res = $plugin->handleWebhook([
        'method'     => (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        'headers'    => $headers,
        'query'      => $_GET,
        'body'       => (string) file_get_contents('php://input', false, null, 0, 65536),
        'account_id' => $accountId,
    ], $pdo);
    $respond((int) ($res['status'] ?? 200), (string) ($res['body'] ?? 'ok'));
} catch (\Throwable $e) {
    Logger::warn('Webhook wtyczki ' . $account['type'] . ' nie obsłużony: ' . $e->getMessage(), ['account' => $accountId]);
    $respond(400, 'temporary error');
}
