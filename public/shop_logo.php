<?php
declare(strict_types=1);

/**
 * Logo sklepu do maili ({{shop_logo}}) - publiczne, bo program pocztowy klienta pobiera obrazek sam.
 * Zwraca tylko logo (zapisany wcześniej obrazek PNG/JPG/GIF), nic więcej z konta e-mail.
 */

$config = require dirname(__DIR__) . '/config/config.php';
$pdo = (require PASE_ROOT . '/config/database.php')($config['db']);

$a = (new \Pase\Services\MailAccounts($pdo))->find((int) ($_GET['a'] ?? 0));
$mime = (string) ($a['logo_mime'] ?? '');
if ($a === null || empty($a['logo_data']) || !in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true)) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=31536000, immutable'); // adres zmienia się (v=...) po wgraniu nowego logo
echo base64_decode((string) $a['logo_data']);
