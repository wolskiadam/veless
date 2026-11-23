<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Szablony (e-mail, wydruk, opis oferty) bez skryptów, podgląd w ramce sandbox,
// adres panelu tylko dla administratora, import i pliki pomocnicze zabezpieczone.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php';
    }
});
use Pase\Support\HtmlSanitizer as H;

$checks = 0;
function check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    ++$checks; echo "OK: $label\n";
}

// --- Czysty szablon zostaje bajt w bajt ---
$clean = [
    "<p>Dzień dobry {{customer_name}},</p>\n<p>zamówienie <b>{{ order_number }}</b> jest w drodze: <a href=\"{{tracking_link}}\">śledź</a></p>",
    '<table style="width:100%"><tr><td>{{produkty}}</td></tr></table>',
    '<img src="data:image/png;base64,iVBORw0KGgo=" alt="">',
    '',
];
foreach ($clean as $i => $html) { check(H::clean($html) === $html, "Clean template #$i is unchanged"); }

// --- Groźne fragmenty znikają ---
$bad = [
    '<p>a</p><script>alert(1)</script>'                       => ['<script', 'alert'],
    '<img src="x" onerror="alert(1)">'                         => ['onerror', 'alert'],
    '<a href="javascript:alert(1)">x</a>'                      => ['javascript'],
    '<a href=" jav&#x09;ascript:alert(1)">x</a>'                => ['ascript:'],
    '<a href="&#106;avascript:alert(1)">x</a>'                  => ['avascript'],
    '<iframe src="https://example.com"></iframe>'              => ['<iframe'],
    '<svg onload="alert(1)"></svg>'                            => ['<svg', 'onload'],
    '<form action="https://example.com"><input name=p></form>' => ['<form', '<input'],
    '<meta http-equiv="refresh" content="0;url=https://x">'    => ['<meta'],
    '<base href="https://example.com/">'                       => ['<base'],
    '<object data="x.swf"></object><embed src="x">'            => ['<object', '<embed'],
    '<img src="data:text/html;base64,PHNjcmlwdD4=">'           => ['data:text'],
    '<div style="width:expression(alert(1))">a</div>'          => ['expression'],
    '<p ONCLICK="alert(1)">a</p>'                              => ['onclick', 'alert'],
];
foreach ($bad as $html => $needles) {
    $out = strtolower(H::clean($html));
    foreach ($needles as $n) { check(!str_contains($out, strtolower($n)), "Removed '$n' from: $html"); }
}

// Zmienne i polskie znaki przeżywają czyszczenie, nawet w atrybutach.
$out = H::clean('<p>Zażółć {{customer_name}}</p><a href="{{order_link}}" onclick="x()">Zamówienie</a><script>x()</script>');
check(str_contains($out, 'Zażółć {{customer_name}}'), 'Polish text and variable kept');
check(str_contains($out, 'href="{{order_link}}"'), 'Variable in href kept unencoded');
check(!str_contains($out, 'onclick') && !str_contains($out, '<script'), 'Handler and script removed alongside');

// --- Miejsca użycia ---
$root = dirname(__DIR__);
$read = static fn(string $f): string => (string) file_get_contents($root . '/' . $f);
check(str_contains($read('public/admin/email_templates.php'), 'HtmlSanitizer::clean('), 'E-mail templates are cleaned on save');
check(str_contains($read('public/admin/print_templates.php'), 'HtmlSanitizer::clean('), 'Print templates are cleaned on save');
check(str_contains($read('public/admin/_desc_templates.php'), 'HtmlSanitizer::clean('), 'Offer description modules are cleaned on save');
check(preg_match('/<iframe id="edPreview" sandbox=""/', $read('public/admin/email_templates.php')) === 1, 'E-mail preview iframe is sandboxed');
check(!str_contains($read('public/admin/email_templates.php'), 'doc.write('), 'E-mail preview does not write into a same-origin document');
check(preg_match('/<iframe id="tplPreview"[^>]*sandbox=""/', $read('public/admin/_desc_templates.php')) === 1, 'Description preview iframe is sandboxed');
check(!str_contains($read('public/admin/_desc_templates.php'), 'prev.innerHTML'), 'Description preview does not use innerHTML in the panel');

$um = $read('public/admin/url_migration.php');
check(str_contains($um, 'isAdmin()') && str_contains($um, 'passwordConfirmed()'), 'Panel address change needs admin + password');
$cs = $read('public/admin/client_settings.php');
check(!str_contains($cs, "'APP_BASE_URL'          => rtrim"), 'Client settings no longer saves APP_BASE_URL unconditionally');
check(str_contains($cs, 'isAdmin()') && str_contains($cs, 'passwordConfirmed()'), 'Client settings base URL needs admin + password');

$ht = $read('public/admin/.htaccess');
check(str_contains($ht, '|_[A-Za-z0-9_]+)\.php$'), 'All _*.php partials in admin are denied by the server');
foreach (glob($root . '/public/admin/_*.php') as $f) {
    check(preg_match('/^_[A-Za-z0-9_]+\.php$/', basename($f)) === 1, 'Partial matches the deny rule: ' . basename($f));
}

foreach (['import_products.php', 'import_orders.php'] as $p) {
    $s = $read('public/admin/' . $p);
    check(str_contains($s, "hash_equals(csrfToken(), (string) (\$_GET['t'] ?? ''))"), "$p: run link needs session token");
    check(substr_count($s, 'run=1&t=') + substr_count($s, 'name="t" value="<?= csrfToken() ?>"') >= 2, "$p: links and forms carry the token");
}

$oa = $read('public/order_attachment.php');
check(str_contains($oa, "order_verified_") && str_contains($oa, 'ClientVerify::availableFields'), 'Customer attachment respects the verification gate');

foreach (glob($root . '/.github/workflows/*.yml') as $wf) {
    preg_match_all('/uses:\s*(\S+)/', (string) file_get_contents($wf), $m);
    foreach ($m[1] as $u) {
        check(preg_match('/@[0-9a-f]{40}$/', $u) === 1, basename($wf) . ': action pinned by commit: ' . $u);
    }
}

echo "\nAll $checks checks passed.\n";
