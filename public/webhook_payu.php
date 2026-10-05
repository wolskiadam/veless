<?php
declare(strict_types=1);

/**
 * Powiadomienia PayU o płatnościach z linków wystawionych w CRM (notifyUrl w PayuPayments::createLink).
 *
 * Treści powiadomienia nie ufamy: bierzemy z niej tylko numer płatności i, jeśli to płatność z naszego linku,
 * czytamy jej aktualny stan z PayU własnymi kluczami sklepu. Gdy konto PayU ma „Drugi klucz (MD5)”, najpierw
 * sprawdzamy podpis OpenPayu-Signature i odrzucamy powiadomienia bez niego.
 * PayU oczekuje odpowiedzi 200 - inaczej ponawia powiadomienie.
 */

use Pase\Services\PayuPayments;
use Pase\Services\PayuPayouts;
use Pase\Support\Logger;

$config = require dirname(__DIR__) . '/config/config.php';
Logger::toFile(PASE_ROOT . '/storage/app.log');

$raw = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string) file_get_contents('php://input', false, null, 0, 65536) : '';
http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
echo 'ok';
if ($raw === '') {
    return;
}

try {
    $pdo = (require PASE_ROOT . '/config/database.php')($config['db']);
    \Pase\Services\Handover::exitWhenAway($pdo); // praca przeniesiona na drugą instalację - ta strona nic nie robi
    PayuPayments::migrate($pdo);
    $payouts = new PayuPayouts($pdo);
    // Podpis: sprawdzany, gdy któreś konto PayU ma wpisany „Drugi klucz (MD5)”. Bez niego zostaje odczyt stanu
    // z API PayU własnymi kluczami (treści powiadomienia i tak nie ufamy), ale śmieciowe żądania odpadają od razu.
    $secondKeys = array_values(array_filter(array_map(static fn(array $a): string => trim((string) ($a['config']['second_key'] ?? '')), $payouts->accounts())));
    if ($secondKeys !== [] && !\Pase\Support\WebhookSignature::payuValid($raw, $_SERVER['HTTP_OPENPAYU_SIGNATURE'] ?? '', $secondKeys)) {
        Logger::warn('PayU: powiadomienie z nieprawidłowym podpisem - pominięte');
        return;
    }
    if ((new PayuPayments($pdo, $payouts))->notification($raw)) {
        Logger::info('PayU: powiadomienie - odświeżono płatność z linku');
    }
} catch (\Throwable $e) {
    Logger::warn('PayU: powiadomienie nie obsłużone: ' . $e->getMessage());
}
