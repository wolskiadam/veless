<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Blokady wersji demo (każdy gość jest administratorem) i filtr plików wgrywanych przez przeniesienie danych.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php';
    }
});
defined('PASE_ROOT') || define('PASE_ROOT', dirname(__DIR__));
use Pase\Services\Handover;
use Pase\Support\Demo;
use Pase\Support\Env;

$checks = 0;
function check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    ++$checks; echo "OK: $label\n";
}

// --- Przeniesienie danych: do storage/ tylko dane ---
$ok = ['storage/uploads/faktura.pdf', 'storage/uploads/packing/12-ab.jpg', 'storage/labels/etykieta.zpl', 'storage/documents/2026/fv.pdf'];
foreach ($ok as $e) { check(Handover::importableStorageEntry($e), "Data file is imported: $e"); }
$bad = [
    'storage/.htaccess', 'storage/release.json', 'storage/x.php', 'storage/uploads/.htaccess', 'storage/uploads/.user.ini',
    'storage/uploads/shell.php', 'storage/uploads/shell.PHP', 'storage/uploads/shell.phtml', 'storage/uploads/shell.php.jpg',
    'storage/uploads/a.phar', 'storage/security/totp.key', 'storage/sessions/sess_abc', 'storage/updates/veless-9.9.zip',
    'storage/agent-builds/agent.exe', 'storage/backups/full/x.zip', 'storage/.hidden/x.pdf', 'storage/uploads/../x.pdf',
    'storage/handover/.token', 'config/config.php', 'storage/',
];
foreach ($bad as $e) { check(!Handover::importableStorageEntry($e), "Not imported: $e"); }

// --- Demo ---
$envFile = tempnam(sys_get_temp_dir(), 'crm-env');
file_put_contents($envFile, "DEMO_MODE=1\n");
Env::load($envFile);
unlink($envFile);
check(Demo::on(), 'DEMO_MODE=1 switches the demo on');
foreach (['/x/public/handover.php', '/x/public/admin/handover.php', '/x/public/admin/update.php', '/x/public/agent_update.php'] as $p) {
    check(Demo::blocks($p, 'GET', false) && Demo::blocks($p, 'POST', false), "Blocked in demo: $p");
}
foreach (['server_usage.php', 'document_operations.php', 'menu_order.php', 'security.php', 'push.php', 'quick_approve.php',
          'email_templates.php', 'print_templates.php', 'allegro_templates.php', 'users.php', 'plugins.php'] as $page) {
    check(Demo::blocks('/x/public/admin/' . $page, 'POST', false), "Read-only in demo: $page");
    check(!Demo::blocks('/x/public/admin/' . $page, 'GET', false), "Viewable in demo: $page");
}
check(Demo::blocks('/x/public/admin/brand_new_admin_page.php', 'POST', false), 'Unknown panel page is read-only in demo');
foreach (['products.php', 'order_view.php', 'index.php', 'login.php', 'layout_save.php', 'packing_api.php'] as $page) {
    check(!Demo::blocks('/x/public/admin/' . $page, 'POST', false), "Demo data can be edited: $page");
}
check(!Demo::blocks('/x/public/order.php', 'POST', false), 'Customer page messages work in demo');
check(!Demo::blocks('/x/public/pack/api.php', 'POST', false), 'Phone packing works in demo');
check(Demo::blocks('/x/public/admin/products.php', 'POST', true), 'Uploaded files are still blocked');

echo "demo_guard: {$checks} checks passed\n";
