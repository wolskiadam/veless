<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Standalone regression suite: no production config, DB, API or printer access.
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'Pase\\')) {
        require $root . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php';
    }
});

use Pase\Services\PrintTemplateRenderer;
use Pase\Services\PrintHtml;
use Pase\Support\AdminSession;
use Pase\Support\RateLimiter;
use Pase\Support\WebhookSignature;

$checks = 0;
function check(bool $condition, string $name): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $name);
    }
    ++$checks;
    echo "OK: {$name}\n";
}

function child(string $code): string
{
    $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || $err !== '') {
        throw new RuntimeException('Child PHP failed: ' . $err);
    }
    return $out;
}

$attack = '<img src=x onerror=alert(1)><script>alert(2)</script>';
$html = PrintTemplateRenderer::render('<div>{{klient_imie_nazwisko}}</div>', ['klient_imie_nazwisko' => $attack]);
check(!str_contains($html, '<img') && !str_contains($html, '<script'), 'Order text cannot create HTML elements');
check(str_contains($html, '&lt;img'), 'Escaped customer text remains visible');
$context = PrintTemplateRenderer::contextForOrder(
    ['local_items' => null, 'pase_number' => 443],
    ['billing' => ['first_name' => $attack], 'currency' => 'PLN',
        'line_items' => [['name' => $attack, 'sku' => 'A&B', 'quantity' => 1, 'price' => 12, 'total' => 12]]], 'Sklep'
);
$html = PrintTemplateRenderer::render('<table>{{produkty_tabela}}</table><p>{{klient_imie_nazwisko}}</p>', $context);
check(str_contains($html, '<tr>') && str_contains($html, 'A&amp;B'), 'Generated product table retains markup and escaped SKU');
check(!str_contains($html, '<script') && !str_contains($html, '<img'), 'Imported customer and item fields are passive');

foreach ([
    '<img src="https://evil.example/a" onerror="alert(1)"><script>alert(1)</script>',
    '<svg><g onload="alert(1)"></g></svg><math><mtext><img src=x onerror=alert(1)></mtext></math>',
    '<iframe srcdoc="<script>alert(1)</script>"></iframe><object data="x"></object>',
    '<form action="/admin/users.php"><input name="action" value="delete"></form>',
    '<div style="background-image:url(https://evil.example);color:red" onclick="alert(1)">Text</div>',
    '<img src="javascript:alert(1)"><a href="javascript:alert(1)">bad</a>',
    '<style>@import "https://evil.example";</style><meta http-equiv="refresh" content="0;url=https://evil.example">',
] as $i => $payload) {
    $safe = PrintHtml::sanitize($payload);
    check(!preg_match('/<(script|svg|math|iframe|object|form|input|style|meta|a)\b|\son\w+=|https:|javascript:|url\(/i', $safe), 'Active template payload removed #' . $i);
}
$safe = PrintHtml::sanitize('<img src="print_template_image.php?tpl=12" style="max-width:100%;height:40px"><p style="color:red;margin:10px">Zażółć</p>');
check(str_contains($safe, 'print_template_image.php?tpl=12') && str_contains($safe, 'max-width:100%'), 'Uploaded logo and passive inline styling preserved');
check(str_contains($safe, 'Zażółć'), 'UTF-8 preserved');
check(str_contains(PrintHtml::sanitize('<img src="data:image/png;base64,YWJj">'), 'data:image/png'), 'Embedded agent logo preserved');
check(!str_contains(PrintHtml::sanitize('<img src="data:image/svg+xml;base64,YWJj">'), 'src='), 'SVG data URL rejected');
$zpl = PrintTemplateRenderer::render('^XA^FD{{name}}^FS^XZ', ['name' => 'A&B ^XZ~JA'], 'ZPL');
check($zpl === '^XA^FDA&B XZJA^FS^XZ', 'ZPL template commands retained, injected commands removed');

$user = ['id' => 1, 'password_hash' => 'hash', 'role' => 'admin', 'is_active' => 1, 'session_version' => 1];
$session = ['pase_auth_stamp' => AdminSession::stamp($user)];
check(AdminSession::valid($user, $session), 'Current session accepted');
check(!AdminSession::valid($user, []), 'Legacy sessions require login');
check(!AdminSession::valid(false, $session), 'Deleted user rejected');
foreach (['password_hash' => 'new-hash', 'role' => 'viewer', 'is_active' => 0, 'session_version' => 2, 'id' => 2] as $key => $value) {
    check(!AdminSession::valid(array_replace($user, [$key => $value]), $session), 'Session revoked by change: ' . $key);
}
check(!AdminSession::valid(array_replace($user, ['is_active' => 1, 'session_version' => 3]), $session), 'Reactivation does not restore old session');

$body = '{"id":123}';
$secret = 'test-only-secret';
$signature = base64_encode(hash_hmac('sha256', $body, $secret, true));
check(WebhookSignature::valid($body, $secret, $signature), 'Valid Woo signature accepted');
check(!WebhookSignature::valid($body . ' ', $secret, $signature), 'Changed webhook body rejected');
foreach (['', ' ', null] as $missing) {
    check(!WebhookSignature::valid($body, $missing, $signature), 'Missing/blank webhook secret rejected');
}
check(!WebhookSignature::valid($body, $secret, ''), 'Missing signature rejected');
check(!WebhookSignature::valid($body, $secret, []), 'Malformed signature rejected');

$temp = sys_get_temp_dir() . '/crm-security-' . bin2hex(random_bytes(8));
mkdir($temp, 0700);
try {
    $file = $temp . '/limits.json';
    $limits = ['account:1' => [2, 900], 'ip:test' => [3, 900]];
    check((new RateLimiter($file))->consume($limits, 1000), 'First attempt accepted');
    check((new RateLimiter($file))->consume($limits, 1001), 'Counter survives new limiter/session');
    check(!(new RateLimiter($file))->consume($limits, 1002), 'Account limit enforced');
    check((new RateLimiter($file))->consume(['account:2' => [2, 900], 'ip:test' => [3, 900]], 1003), 'Other account can use remaining IP budget');
    check(!(new RateLimiter($file))->consume(['account:3' => [2, 900], 'ip:test' => [3, 900]], 1004), 'IP budget covers different accounts');
    check((new RateLimiter($file))->consume($limits, 1900), 'Limit expires');
    check(!str_contains(file_get_contents($file), 'account:1'), 'Limiter stores hashes, not identifiers');
    file_put_contents($file, '{broken');
    check(!(new RateLimiter($file))->consume($limits), 'Corrupt limiter fails closed');
    check(!(new RateLimiter($temp))->consume($limits), 'Unwritable limiter fails closed');

    // Simultaneous clients must share the same atomic budget.
    $concurrent = $temp . '/parallel.json';
    $processes = [];
    for ($i = 0; $i < 12; ++$i) {
        $code = 'require ' . var_export($root . '/src/Support/RateLimiter.php', true) . ';'
            . 'echo (new \\Pase\\Support\\RateLimiter(' . var_export($concurrent, true) . '))->consume(["one" => [5,900]]) ? "1" : "0";';
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $processes[] = [$process, $pipes];
    }
    $accepted = 0;
    foreach ($processes as [$process, $pipes]) {
        $accepted += (int) stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        check(proc_close($process) === 0 && $err === '', 'Concurrent limiter process clean');
    }
    check($accepted === 5, 'Exactly five concurrent attempts accepted');
} finally {
    foreach (glob($temp . '/*.json') ?: [] as $file) {
        unlink($file);
    }
    rmdir($temp);
}

// Run the actual shared guard functions in isolated PHP processes.
$auth = file_get_contents($root . '/public/admin/auth.php');
$functions = substr($auth, strpos($auth, 'function currentUserId()'));
foreach ([
    ['GET', 'admin', 'token', 'token', 405],
    ['POST', 'viewer', 'token', 'token', 403],
    ['POST', 'admin', 'token', '', 419],
    ['POST', 'editor', '', '', 419],
    ['POST', 'admin', 'token', [], 419],
    ['POST', 'admin', 'token', 'wrong', 419],
    ['POST', 'admin', 'token', 'token', 200],
    ['POST', 'editor', 'token', 'token', 200],
] as [$method, $role, $stored, $sent, $status]) {
    $code = 'function t($key, $vars = []) { return $key; }'
        . '$_SERVER["REQUEST_METHOD"]=' . var_export($method, true) . ';'
        . '$_SESSION=' . var_export(['pase_user_role' => $role, 'csrf' => $stored], true) . ';'
        . '$_POST=' . var_export(['csrf' => $sent], true) . ';'
        . 'register_shutdown_function(function(){echo "STATUS=".(http_response_code() ?: 200);});'
        . $functions . "\nrequirePrintPost();";
    check(str_ends_with(child($code), 'STATUS=' . $status), "Print guard {$method}/{$role}: {$status}");
}
foreach (['print_document.php', 'attachment.php', 'label_download.php', 'print_asset.php'] as $route) {
    $source = file_get_contents($root . '/public/admin/' . $route);
    check(strpos($source, 'requirePrintPost();') < strpos($source, '->enqueue('), $route . ' protects queue mutation');
}
foreach (['install.php', 'webhook_allegro.php'] as $route) {
    $code = '$_GET=["force"=>"1"]; $_SERVER["REQUEST_METHOD"]="POST";'
        . 'register_shutdown_function(function(){echo "STATUS=".http_response_code();});'
        . 'require ' . var_export($root . '/public/' . $route, true) . ';';
    check(str_ends_with(child($code), 'STATUS=410'), $route . ' disabled even for forced POST');
}

// Zmienne w treści HTML maila: dane kupującego nie wstawiają znaczników ani linków.
$mailHtml = \Pase\Services\Mailer::renderHtml('<p>Witaj {{customer_name}}</p>{{product_list}}<a href="{{tracking_link}}">x</a>', [
    'customer_name' => '<a href="https://phish.example">Kliknij</a>',
    'product_list'  => '1 × Świeca<br>',
    'tracking_link' => 'https://track.example/?a=1&b="2"',
]);
check(!str_contains($mailHtml, '<a href="https://phish.example">'), 'Buyer name escaped in HTML email');
check(str_contains($mailHtml, '&lt;a href=&quot;https://phish.example&quot;&gt;Kliknij&lt;/a&gt;'), 'Escaped buyer name stays readable');
check(str_contains($mailHtml, '1 × Świeca<br>'), 'Product list keeps its own safe HTML');
check(str_contains($mailHtml, 'href="https://track.example/?a=1&amp;b=&quot;2&quot;"'), 'Link variable cannot break out of href');

// Podpis PayU (OpenPayu-Signature): hash(treść + drugi klucz), MD5 lub SHA-256.
$payuBody = '{"order":{"orderId":"ABCDEFGHIJ12"}}';
$payuHeader = 'sender=checkout;signature=' . md5($payuBody . 'second-key') . ';algorithm=MD5;content=DOCUMENT';
check(\Pase\Support\WebhookSignature::payuValid($payuBody, $payuHeader, ['other', 'second-key']), 'PayU MD5 signature accepted with matching second key');
check(\Pase\Support\WebhookSignature::payuValid($payuBody, 'signature=' . hash('sha256', $payuBody . 'k') . ';algorithm=SHA-256', ['k']), 'PayU SHA-256 signature accepted');
check(!\Pase\Support\WebhookSignature::payuValid($payuBody . ' ', $payuHeader, ['second-key']), 'PayU signature rejects changed body');
check(!\Pase\Support\WebhookSignature::payuValid($payuBody, $payuHeader, ['wrong']), 'PayU signature rejects wrong key');
check(!\Pase\Support\WebhookSignature::payuValid($payuBody, '', ['second-key']), 'PayU notification without signature rejected');
check(!\Pase\Support\WebhookSignature::payuValid($payuBody, 'signature=abc;algorithm=CRC32', ['second-key']), 'Unknown PayU algorithm rejected');
echo "PASS: {$checks} checks\n";
