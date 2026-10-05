<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Dziennik wysłanych e-maili: zapis każdej próby z Mailer::send(), liczniki wg rodzaju,
// szablonu, nadawcy i dnia (czas polski), filtry listy i sprzątanie starych wpisów (bez sieci).
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Services\EmailLog;
use Pase\Services\Mailer;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
EmailLog::migrate($pdo);
EmailLog::migrate($pdo);
Pase\Support\Runtime::setPdo($pdo);
$log = new EmailLog($pdo);

// --- Mailer zapisuje także próby, które nie doszły do SMTP ---
[$ok, $msg] = (new Mailer(['host' => 'smtp.example.test', 'from_email' => 'sklep@example.test']))
    ->send('to-nie-jest-adres', 'Twoje zamówienie', '<p>x</p>', ['type' => 'automation', 'template' => 'order_shipped', 'order_id' => 501]);
check(!$ok, 'invalid recipient is not sent');
$row = $log->entries([], 10)[0] ?? null;
check($row !== null && $row['type'] === 'automation' && $row['template_key'] === 'order_shipped' && (int) $row['woo_order_id'] === 501,
    'attempt logged with type, template and order');
check($row['status'] === 'failed' && str_contains((string) $row['error'], 'Nieprawidłowy adres') && $row['sender'] === 'sklep@example.test',
    'failure reason and sender account kept');
check($row['source'] === 'cron' && $row['actor_name'] === null, 'CLI send attributed to the automatic process');

(new Mailer([]))->send('jan@example.test', 'Test', '<p>x</p>', ['type' => 'smtp_test']);
(new Mailer([]))->send('jan@example.test', 'Bez rodzaju', '<p>x</p>');
$types = array_column($log->entries([], 10), 'type');
check($types === ['other', 'smtp_test', 'automation'], 'missing SMTP config logged; send without meta counted as "other"');

// --- Udane wysyłki (zapis bezpośredni, bez serwera SMTP) ---
$_SESSION = ['pase_user_id' => 7, 'pase_username' => 'ania'];
$log->record(['type' => 'order_message', 'order_id' => 501], 'klient@example.test', 'Wiadomość do zamówienia', 'sklep@example.test', true);
$_SESSION = [];
$log->record(['type' => 'automation', 'template' => 'order_shipped', 'order_id' => '502'], 'b@example.test', 'Wysłane', 'marka2@example.test', true);
$log->record(['type' => 'nieznany'], 'c@example.test', 'X', '', true);
$panel = $log->entries(['type' => 'order_message'], 5)[0];
check($panel['actor_name'] === 'ania' && $panel['status'] === 'sent' && $panel['error'] === null, 'panel user recorded, sent without error');
check($log->entries([], 1)[0]['type'] === 'other', 'unknown type stored as "other"');

// --- Liczniki ---
$byType = $log->countsByType([]);
check($byType['automation'] === ['sent' => 1, 'failed' => 1] && $byType['other'] === ['sent' => 1, 'failed' => 1], 'counts by type split sent/failed');
check($log->countsByTemplate([]) === [['template_key' => 'order_shipped', 'sent' => 1, 'failed' => 1]], 'counts by template');
$senders = array_column($log->countsBySender([]), null, 'sender');
check($senders['sklep@example.test']['sent'] === 1 && $senders['sklep@example.test']['failed'] === 1 && isset($senders['']), 'counts by sender account');

// --- Filtry ---
check($log->count(['status' => 'failed']) === 3 && $log->count(['status' => 'sent']) === 3, 'status filter');
check($log->count(['order_id' => 501]) === 2, 'order filter');
$auto = $log->forOrder(501);
check(count($auto) === 1 && $auto[0]['type'] === 'automation' && $auto[0]['template_key'] === 'order_shipped', 'order thread gets only automation mails of that order');
check(count($log->forOrder(501, ['automation', 'order_message'])) === 2 && $log->forOrder(0) === [] && $log->forOrder(501, ['nieznany']) === [], 'forOrder types and guards');
check($log->count(['q' => 'klient@']) === 1 && $log->count(['q' => 'order_ship']) === 2 && $log->count(['q' => '100%']) === 0, 'search in recipient / template, LIKE wildcards escaped');
check($log->count(['type' => 'nie-ma-takiego']) === 6, 'unknown type filter ignored');

// --- Dni w czasie polskim: 23:30 UTC 30.09 to już 1.10 w Polsce ---
$pdo->exec('DELETE FROM email_log');
$ins = $pdo->prepare("INSERT INTO email_log (created_at, type, recipient, subject, status) VALUES (?, 'other', 'a@b.c', 's', ?)");
foreach ([['2026-09-30 21:30:00', 'sent'], ['2026-09-30 22:30:00', 'sent'], ['2026-09-30 23:30:00', 'failed'], ['2026-09-29 10:00:00', 'sent']] as $r) { $ins->execute($r); }
check($log->countsByDay([]) === ['2026-10-01' => ['sent' => 1, 'failed' => 1], '2026-09-30' => ['sent' => 1, 'failed' => 0], '2026-09-29' => ['sent' => 1, 'failed' => 0]],
    'daily counts use Polish local days, newest first');
check($log->count(['from' => '2026-09-30 00:00:00', 'to' => '2026-09-30 22:00:00']) === 1, 'period bounds: from inclusive, to exclusive');

// --- Sprzątanie ---
$ins->execute([gmdate('Y-m-d H:i:s', time() - 400 * 86400), 'sent']);
$ins->execute([gmdate('Y-m-d H:i:s'), 'sent']);
$before = $log->count([]);
$removed = $log->purge();
$cutoff = gmdate('Y-m-d H:i:s', time() - EmailLog::KEEP_DAYS * 86400);
check($removed >= 1 && $log->count(['to' => $cutoff]) === 0 && $log->count(['from' => $cutoff]) === $before - $removed && $log->count(['from' => $cutoff]) >= 1,
    'entries older than a year purged, recent kept');

echo "\n{$checks} checks passed\n";
