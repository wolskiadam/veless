<?php
declare(strict_types=1);

/**
 * Zgłoszenia naruszeń CSP z panelu (Content-Security-Policy-Report-Only, Pase\Support\SecurityHeaders).
 * Tylko zapis do storage/app.log - do zebrania listy, co trzeba poprawić przed włączeniem blokowania.
 * Limit wpisów chroni log przed zalaniem; treść jest przycinana i nie trafia nigdzie indziej.
 */

use Pase\Support\Logger;
use Pase\Support\RateLimiter;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}
http_response_code(204);

$config = require dirname(__DIR__) . '/config/config.php';
Logger::toFile(PASE_ROOT . '/storage/app.log');

$raw = (string) file_get_contents('php://input', false, null, 0, 8192);
$data = json_decode($raw, true);
$r = is_array($data) ? ($data['csp-report'] ?? $data[0]['body'] ?? null) : null;
if (!is_array($r)) {
    return;
}
$limiter = new RateLimiter(PASE_ROOT . '/storage/security/csp-report.json');
if (!$limiter->consume(['all' => [60, 3600], 'ip:' . ($_SERVER['REMOTE_ADDR'] ?? '') => [20, 3600]])) {
    return;
}
$field = static fn(string ...$keys): string => mb_substr(preg_replace('/[\x00-\x1f]+/', ' ', (string) ($r[$keys[0]] ?? $r[$keys[1]] ?? '')) ?? '', 0, 200);
Logger::warn('CSP (raport): ' . $field('violated-directive', 'effectiveDirective')
    . ' | zablokowano: ' . $field('blocked-uri', 'blockedURL')
    . ' | strona: ' . (string) parse_url($field('document-uri', 'documentURL'), PHP_URL_PATH));
