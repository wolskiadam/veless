<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Faktura z wFirma jako załącznik e-maila: szablon z „Dołącz fakturę”, PDF pobrany przy wysyłce
// (automatyzacja i odpowiedź z karty zamówienia), bez faktury e-mail nie wychodzi.
// SQLite, wFirma podstawiona, SMTP to lokalny serwer testowy - bez sieci.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
define('PASE_ROOT', sys_get_temp_dir() . '/pase_invoice_email_' . bin2hex(random_bytes(4)));
mkdir(PASE_ROOT . '/storage', 0777, true);

use Pase\Automation\RuleEngine;
use Pase\Repository\AutomationRuleRepository;
use Pase\Services\EmailLog;
use Pase\Services\InvoicePdf;
use Pase\Services\Mailer;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

/** Rozkłada surową wiadomość: [nagłówki, części MIME (nagłówki + zdekodowana treść)]. */
function mimeParts(string $raw): array
{
    [$head, $body] = explode("\r\n\r\n", $raw, 2);
    preg_match('/boundary="([^"]+)"/', $head, $m);
    $parts = [];
    foreach (array_slice(explode('--' . $m[1], $body), 1, -1) as $chunk) {
        [$h, $b] = explode("\r\n\r\n", ltrim($chunk, "\r\n"), 2);
        $parts[] = ['headers' => $h, 'body' => str_contains($h, 'base64') ? base64_decode(preg_replace('/\s+/', '', $b)) : $b];
    }
    return [$head, $parts];
}

$pdf = "%PDF-1.4\n% faktura testowa \xE2\x80\x94 binarne \x00\xFF\n%%EOF";

// --- Mailer: bez załączników bez zmian, z załącznikiem multipart/mixed ---
$mailer = new Mailer(['host' => 'x', 'from_email' => 'sklep@example.test', 'from_name' => 'Sklep']);
$plain = $mailer->buildMessage('sklep@example.test', 'Sklep', 'jan@example.test', 'Faktura', '<p>Treść</p>');
check(str_contains($plain, 'Content-Type: multipart/alternative') && !str_contains($plain, 'multipart/mixed'), 'mail without attachments stays multipart/alternative');
$raw = $mailer->buildMessage('sklep@example.test', 'Sklep', 'jan@example.test', 'Faktura', '<p>Treść</p>',
    [['name' => 'Faktura-1001.pdf', 'mime' => 'application/pdf', 'content' => $pdf]]);
[$head, $parts] = mimeParts($raw);
check(str_contains($head, 'Content-Type: multipart/mixed') && count($parts) === 2, 'mail with attachment is multipart/mixed: body + file');
check(str_contains($parts[0]['headers'], 'multipart/alternative'), 'first part keeps the text/html alternative');
check(str_contains($parts[1]['headers'], 'Content-Type: application/pdf') && str_contains($parts[1]['headers'], 'Content-Disposition: attachment; filename="=?UTF-8?B?' . base64_encode('Faktura-1001.pdf') . '?="'),
    'attachment part has pdf type and file name');
check($parts[1]['body'] === $pdf, 'attachment content survives base64 byte for byte');

// --- Baza ---
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE woo_orders (woo_order_id INTEGER PRIMARY KEY, integration_id INTEGER, pase_number INTEGER, order_number TEXT, status TEXT,
        customer_email TEXT, payload TEXT, local_items TEXT NULL, merged_into INT NULL);
    CREATE TABLE integration_accounts (id INTEGER PRIMARY KEY, type TEXT, name TEXT, is_active INTEGER, config TEXT);
    INSERT INTO integration_accounts VALUES (1, 'wfirma', 'wFirma', 1, '{\"company_id\":\"9\"}'), (2, 'wfirma', 'stare', 0, '{}');
    CREATE TABLE order_documents (id INTEGER PRIMARY KEY AUTOINCREMENT, woo_order_id INTEGER, integration_id INTEGER,
        provider TEXT, document_type TEXT, remote_id TEXT, status TEXT, message TEXT);
    CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT);
    CREATE TABLE shipments (id INTEGER PRIMARY KEY, woo_order_id INTEGER, status TEXT, tracking_status TEXT NULL, waybill_no TEXT NULL, courier_code TEXT NULL);
    CREATE TABLE email_templates (id INTEGER PRIMARY KEY, tpl_key TEXT, name TEXT, subject TEXT, body TEXT, is_active INTEGER, mail_account_id INTEGER NULL, attach_invoice INTEGER NOT NULL DEFAULT 0);");
EmailLog::migrate($pdo);
Pase\Support\Runtime::setPdo($pdo);
$payload = json_encode(['number' => '1001', 'billing' => ['first_name' => 'Ola', 'email' => 'ola@example.test']]);
$ins = $pdo->prepare('INSERT INTO woo_orders (woo_order_id, integration_id, pase_number, order_number, customer_email, payload, merged_into) VALUES (?, 5, ?, ?, ?, ?, ?)');
$ins->execute([1001, 77, '1001', 'ola@example.test', $payload, null]);
$ins->execute([1002, 78, '1002', 'ola@example.test', $payload, 1001]);   // scalone z 1001
$ins->execute([1003, 79, '1003', 'ola@example.test', $payload, null]);   // bez faktury
$ins->execute([1004, 80, 'A/7 x', 'ola@example.test', $payload, null]);  // faktura na wyłączonym koncie
$pdo->exec("INSERT INTO order_documents (woo_order_id, integration_id, provider, document_type, remote_id, status) VALUES
    (1001, 1, 'wfirma', 'normal', '555', 'issued'), (1003, 1, 'wfirma', 'receipt', '556', 'issued'), (1004, 2, 'wfirma', 'normal', '557', 'issued')");

// --- InvoicePdf ---
$downloads = [];
$wfirma = static function (array $cfg, string $id) use (&$downloads, $pdf): string { $downloads[] = [$cfg['company_id'] ?? '', $id]; return $pdf; };
$svc = new InvoicePdf($pdo, $wfirma);
$file = $svc->forOrder(1001);
check($file === ['name' => 'Faktura-1001.pdf', 'mime' => 'application/pdf', 'content' => $pdf] && $downloads === [['9', '555']],
    'invoice PDF downloaded from the account that issued it');
check($svc->forOrder(1002)['name'] === 'Faktura-1001.pdf' && $downloads[1] === ['9', '555'], 'merged order gets the main order invoice');
$err = static function (callable $f): string { try { $f(); return ''; } catch (RuntimeException $e) { return $e->getMessage(); } };
check(str_contains($err(fn() => $svc->forOrder(1003)), 'nie ma faktury'), 'receipt is not an invoice - clear error');
check(str_contains($err(fn() => $svc->forOrder(1004)), 'wyłączona'), 'inactive wFirma account - clear error');
check(str_contains($err(fn() => (new InvoicePdf($pdo, static fn() => '{"status":{"code":"ERROR"}}'))->forOrder(1001)), 'nie zwróciła pliku PDF'),
    'non-PDF answer from wFirma is rejected');

// --- Lokalny serwer SMTP: zapisuje DATA każdej wiadomości do pliku ---
$spool = PASE_ROOT . '/smtp';
mkdir($spool);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
fclose($server);
$smtp = <<<'PHP'
$s = stream_socket_server('tcp://127.0.0.1:' . $argv[1]); $n = 0;
while ($c = @stream_socket_accept($s, 30)) {
    fwrite($c, "220 test\r\n"); $data = null;
    while (($l = fgets($c)) !== false) {
        if ($data !== null) { if ($l === ".\r\n") { file_put_contents($argv[2] . '/' . (++$n) . '.eml', $data); $data = null; fwrite($c, "250 ok\r\n"); } else { $data .= $l; } continue; }
        if (str_starts_with($l, 'DATA')) { $data = ''; fwrite($c, "354 go\r\n"); }
        elseif (str_starts_with($l, 'QUIT')) { fwrite($c, "221 bye\r\n"); break; }
        else { fwrite($c, "250 ok\r\n"); }
    }
    fclose($c);
}
PHP;
$proc = proc_open([PHP_BINARY, '-r', $smtp, (string) $port, $spool], [], $pipes);
for ($i = 0; $i < 50 && !($probe = @stream_socket_client("tcp://127.0.0.1:$port", $e1, $e2, 1)); $i++) { usleep(100000); }
if ($probe) { fwrite($probe, "QUIT\r\n"); fgets($probe); fgets($probe); fclose($probe); }
$pdo->exec("INSERT INTO settings VALUES ('MAIL_SMTP_HOST', '127.0.0.1'), ('MAIL_SMTP_PORT', '$port'), ('MAIL_SMTP_SECURE', 'none'),
    ('MAIL_FROM_EMAIL', 'sklep@example.test'), ('MAIL_FROM_NAME', 'Sklep')");
$sent = static fn(): array => array_map('file_get_contents', glob($spool . '/*.eml') ?: []);

// --- Automatyzacja „Wyślij e-mail” ---
$pdo->exec("INSERT INTO email_templates (tpl_key, name, subject, body, is_active, attach_invoice) VALUES
    ('basic_invoice', 'Faktura', 'Faktura została wystawiona', '<p>Faktura do zamówienia {{order_number}}</p>', 1, 1),
    ('basic_review', 'Oceń nas', 'Oceń nas', '<p>Oceń</p>', 1, 0)");
$engine = new RuleEngine($pdo, new AutomationRuleRepository($pdo), [], null, static fn() => new InvoicePdf($pdo, $wfirma));
$send = Closure::bind(fn(int $id, string $key) => $this->sendEmail($id, ['id' => 9], $key, json_decode($GLOBALS['payload'], true)), $engine, RuleEngine::class);

$send(1001, 'basic_invoice');
$mails = $sent();
check(count($mails) === 1, 'automation sends the invoice template');
[$head, $parts] = mimeParts($mails[0]);
check(str_contains($head, 'multipart/mixed') && $parts[1]['body'] === $pdf && $parts[1]['headers'] !== '', 'automation e-mail carries the invoice PDF');
$send(1001, 'basic_review');
[$head] = mimeParts($sent()[1]);
check(!str_contains($head, 'multipart/mixed'), 'template without the option sends no attachment');

$send(1003, 'basic_invoice');
check(count($sent()) === 2, 'no invoice -> invoice e-mail is not sent');
$last = (new EmailLog($pdo))->entries([], 1)[0];
check($last['status'] === 'failed' && (int) $last['woo_order_id'] === 1003 && str_contains((string) $last['error'], 'nie ma faktury'),
    'skipped invoice e-mail shows the reason in the e-mail log');

// --- Odpowiedź z karty zamówienia (OrderMessageService) ---
$pdo->exec('CREATE TABLE order_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, woo_order_id INTEGER, sender TEXT, body TEXT, author_name TEXT, is_read INTEGER DEFAULT 0,
    attachment_path TEXT NULL, attachment_name TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP, read_at TEXT NULL)');
$messages = new Pase\Repository\OrderMessageRepository($pdo);
$staff = new Pase\Services\OrderMessageService($pdo, $messages, new Pase\Repository\SettingsRepository($pdo));
$order = $pdo->query('SELECT * FROM woo_orders WHERE woo_order_id = 1001')->fetch();
$staff->fromStaff($order, 'Dzień dobry, w załączniku faktura.', 'Ania', null, $svc->forOrder(1001));
$mails = $sent();
[$head, $parts] = mimeParts($mails[2]);
check(count($mails) === 3 && str_contains($head, 'multipart/mixed') && $parts[1]['body'] === $pdf, 'reply from the order page carries the invoice PDF');
$msg = $pdo->query('SELECT * FROM order_messages ORDER BY id DESC LIMIT 1')->fetch();
$stored = Pase\Services\Attachments::absolutePath((string) $msg['attachment_path']);
check($msg['attachment_name'] === 'Faktura-1001.pdf' && $stored !== null && file_get_contents($stored) === $pdf, 'invoice also saved in the order thread for the client page');

// --- Testowa wysyłka szablonu (Konfiguracja → Szablony e-mail) ---
$test = new Pase\Services\TemplateTestMail($pdo, static fn(int $id) => (new InvoicePdf($pdo, $wfirma))->forOrder($id));
$tplInvoice = $pdo->query("SELECT * FROM email_templates WHERE tpl_key = 'basic_invoice'")->fetch();
$r = $test->send($tplInvoice, 'adam@example.test', '#77');
[$head, $parts] = mimeParts($sent()[3]);
check($r['ok'] && str_contains($head, 'To: <adam@example.test>') && str_contains($head, '=?UTF-8?B?' . base64_encode('[TEST] Faktura została wystawiona') . '?=')
    && $parts[1]['body'] === $pdf && str_contains($parts[0]['body'], base64_encode('<p>Faktura do zamówienia 1001</p>')), 'template test goes to the given address with order data, [TEST] subject and invoice');
$r = $test->send($tplInvoice, 'adam@example.test');
check($r['ok'] && str_contains($r['message'], 'przykładowymi') && str_contains($r['message'], 'Bez faktury') && str_contains($sent()[4], base64_encode('<p>Faktura do zamówienia 12345</p>')), 'template test without an order uses sample data');
check(!$test->send($tplInvoice, 'adam@example.test', '999')['ok'] && !$test->send($tplInvoice, 'zly-adres')['ok'] && count($sent()) === 5, 'unknown order or bad address sends nothing');
check((new EmailLog($pdo))->entries([], 1)[0]['type'] === 'template_test', 'template test is logged as its own kind');

proc_terminate($proc);
exec('rm -rf ' . escapeshellarg(PASE_ROOT));
echo "\ninvoice_email: {$checks} checks passed\n";
