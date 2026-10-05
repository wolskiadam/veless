<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Services\SectionBackup;

// Kopie częściowe z panelu (System → Kopia zapasowa): fikcyjne dane w SQLite.
$checks = 0;
function check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    ++$checks; echo "OK: $label\n";
}
function rejects(callable $fn, string $label): void {
    try { $fn(); } catch (Throwable) { check(true, $label); return; }
    check(false, $label);
}
function rmTree(string $dir): void {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

$temp = sys_get_temp_dir() . '/crm-section-test-' . bin2hex(random_bytes(8));
mkdir($temp . '/storage/uploads', 0700, true);
try {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)");
    $pdo->exec("CREATE TABLE integrations (id INTEGER PRIMARY KEY, platform_name TEXT UNIQUE, access_token TEXT, refresh_token TEXT, expires_at TEXT, meta TEXT)");
    $pdo->exec("CREATE TABLE order_statuses (id INTEGER PRIMARY KEY, status_key TEXT, label TEXT)");
    $pdo->exec("CREATE TABLE print_templates (id INTEGER PRIMARY KEY, name TEXT, body TEXT, image_path TEXT)");
    $pdo->exec("CREATE TABLE woo_orders (id INTEGER PRIMARY KEY, woo_order_id INTEGER, order_number TEXT, status TEXT, total DECIMAL(12,2), currency TEXT, customer_name TEXT, customer_email TEXT, date_created TEXT, payload TEXT)");
    $pdo->exec("CREATE TABLE order_messages (id INTEGER PRIMARY KEY, woo_order_id INTEGER, body TEXT, attachment_path TEXT)");
    $pdo->exec("CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT, name TEXT)");

    $pdo->exec("INSERT INTO settings VALUES ('MAIL_FROM_NAME','Świece'), ('ALLEGRO_LAST_ORDER_EVENT_ID','100'), ('SYNC_ORDERS_AT','111')");
    $pdo->exec("INSERT INTO integrations VALUES (1,'allegro','OLD-ACCESS','OLD-REFRESH','2026-01-01','{\"a\":1}')");
    $pdo->exec("INSERT INTO order_statuses VALUES (1,'new','Nowe'), (2,'packed','Spakowane')");
    file_put_contents($temp . '/storage/uploads/aa11.png', "PNG\0\xff binary");
    $pdo->exec("INSERT INTO print_templates VALUES (1,'Etykieta','<b>{{x}}</b>','uploads/aa11.png')");
    $pdo->prepare('INSERT INTO woo_orders VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([1, 501, 'A-501', 'new', '89.70', 'PLN', 'Jan Kowalski', '=HYPERLINK(1)', '2026-09-01 10:00:00', "{\"note\":\"zażółć\"}"]);
    $pdo->prepare('INSERT INTO woo_orders VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([2, 502, 'A-502', 'sent', '12.00', 'PLN', 'Anna', 'a@x.pl', '2026-09-02 10:00:00', "bin\xfe\xff"]);
    file_put_contents($temp . '/storage/uploads/bb22.pdf', '%PDF-test');
    $pdo->exec("INSERT INTO order_messages VALUES (1,501,'Dzień dobry','uploads/bb22.pdf'), (2,501,'zły plik','../../.env')");
    $pdo->exec("INSERT INTO products VALUES (1,'SKU-1','Świeca')");

    $svc = new SectionBackup($pdo, $temp . '/storage', 'CRM v1.0.test');
    $pass = 'test-haslo-123';

    rejects(fn() => $svc->export('settings', 'krótkie'), 'Too short password rejected');
    rejects(fn() => $svc->export('wszystko', $pass), 'Unknown part rejected');

    $settingsFile = $svc->export('settings', $pass, 'Przed zmianą');
    $ordersFile   = $svc->export('orders', $pass);
    $productsFile = $svc->export('products', $pass);
    check(count($svc->list()) === 3, 'Three separate backups listed');
    check(is_file($temp . '/storage/backups/sections/.htaccess') && is_file($temp . '/storage/backups/.htaccess'), 'Backup directory blocked from web');
    $list = array_column($svc->list(), null, 'file');
    check($list[$settingsFile]['part'] === 'settings' && $list[$settingsFile]['note'] === 'Przed zmianą', 'List readable without password');

    $m = $svc->verify($svc->path($settingsFile), $pass);
    check($m['part'] === 'settings' && !isset($m['tables']['woo_orders']) && !isset($m['tables']['products']), 'Settings backup has no order/product data');
    check($m['tables']['settings']['rows'] === 1, 'Runtime settings (cursor, sync times) excluded');
    check(isset($m['files']['files/uploads/aa11.png']), 'Print template image included');
    check(in_array('mail_accounts', $m['missing_tables'], true), 'Missing tables reported, not fatal');
    $m = $svc->verify($svc->path($ordersFile), $pass);
    check($m['part'] === 'orders' && !isset($m['tables']['settings']) && $m['tables']['woo_orders']['rows'] === 2, 'Orders backup has only order data');
    check(isset($m['files']['files/uploads/bb22.pdf']) && isset($m['files']['zamowienia.csv']) && count($m['files']) === 4, 'Attachment + CSV included, unsafe path skipped');
    rejects(fn() => $svc->verify($svc->path($ordersFile), 'zle-haslo-123'), 'Wrong password rejected');

    // Zawartość zaszyfrowana: surowy plik nie zawiera danych klienta.
    $raw = file_get_contents($svc->path($ordersFile));
    check(!str_contains($raw, 'Kowalski') && !str_contains($raw, 'A-501'), 'Order data encrypted in archive');
    rejects(fn() => $svc->path('../../.env'), 'Path traversal in file name rejected');

    $zip = new ZipArchive(); $zip->open($svc->path($ordersFile)); $zip->setPassword($pass);
    $csv = $zip->getFromName('zamowienia.csv'); $zip->close();
    check(str_contains($csv, 'Jan Kowalski') && str_contains($csv, "'=HYPERLINK") && str_starts_with($csv, "\xEF\xBB\xBF"), 'CSV readable, formulas neutralised');

    // Zmiany po kopii, potem przywrócenie ustawień.
    $pdo->exec("UPDATE settings SET setting_value='Zmienione' WHERE setting_key='MAIL_FROM_NAME'");
    $pdo->exec("UPDATE settings SET setting_value='200' WHERE setting_key='ALLEGRO_LAST_ORDER_EVENT_ID'");
    $pdo->exec("INSERT INTO settings VALUES ('NEW_KEY','x')");
    $pdo->exec("UPDATE integrations SET access_token='NEW-ACCESS', refresh_token='NEW-REFRESH', meta='{\"a\":2}'");
    $pdo->exec("DELETE FROM order_statuses WHERE id=2");
    $pdo->exec("INSERT INTO woo_orders (id, woo_order_id, payload) VALUES (3, 503, '{}')");
    unlink($temp . '/storage/uploads/aa11.png');

    $r = $svc->restore($settingsFile, $pass);
    $settings = $pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    check($settings['MAIL_FROM_NAME'] === 'Świece' && !isset($settings['NEW_KEY']), 'Settings restored');
    check($settings['ALLEGRO_LAST_ORDER_EVENT_ID'] === '200' && $settings['SYNC_ORDERS_AT'] === '111', 'Runtime state kept on restore');
    $int = $pdo->query('SELECT * FROM integrations')->fetch(PDO::FETCH_ASSOC);
    check($int['refresh_token'] === 'NEW-REFRESH' && $int['access_token'] === 'NEW-ACCESS' && $int['meta'] === '{"a":1}', 'Current API tokens kept, config restored');
    check((int) $pdo->query('SELECT COUNT(*) FROM order_statuses')->fetchColumn() === 2, 'Deleted status restored');
    check((int) $pdo->query('SELECT COUNT(*) FROM woo_orders')->fetchColumn() === 3, 'Settings restore did not touch orders');
    check(is_file($temp . '/storage/uploads/aa11.png') && file_get_contents($temp . '/storage/uploads/aa11.png') === "PNG\0\xff binary", 'Image file restored');
    check($r['safety'] !== '' && $svc->verify($svc->path($r['safety']), $pass)['part'] === 'settings', 'Safety backup created before restore');

    // Przywrócenie zamówień: usuwa zamówienie dodane po kopii, odtwarza binaria i Unicode.
    $r = $svc->restore($ordersFile, $pass);
    check((int) $pdo->query('SELECT COUNT(*) FROM woo_orders')->fetchColumn() === 2, 'Orders restored to backup state');
    $payloads = $pdo->query('SELECT woo_order_id, payload FROM woo_orders')->fetchAll(PDO::FETCH_KEY_PAIR);
    check($payloads[502] === "bin\xfe\xff" && str_contains($payloads[501], 'zażółć'), 'Binary and Unicode values exact');
    check($pdo->query("SELECT setting_value FROM settings WHERE setting_key='MAIL_FROM_NAME'")->fetchColumn() === 'Świece', 'Orders restore did not touch settings');

    // Kopia zamówień z wybranego okresu: tylko zamówienia z zakresu + ich wiadomości i załączniki.
    rejects(fn() => $svc->export('orders', $pass, '', ['from' => '2026-09-05', 'to' => '2026-09-01']), 'Reversed period rejected');
    rejects(fn() => $svc->export('settings', $pass, '', ['from' => '2026-09-01', 'to' => '2026-09-01']), 'Period only for orders');
    $periodFile = $svc->export('orders', $pass, '', ['from' => '2026-09-01', 'to' => '2026-09-01']);
    $m = $svc->verify($svc->path($periodFile), $pass);
    check($m['period'] === ['from' => '2026-09-01', 'to' => '2026-09-01'] && $m['tables']['woo_orders']['rows'] === 1, 'Period backup has only orders from range');
    check($m['tables']['order_messages']['rows'] === 2 && isset($m['files']['files/uploads/bb22.pdf']), 'Related messages and attachments included');
    $zip = new ZipArchive(); $zip->open($svc->path($periodFile)); $zip->setPassword($pass);
    $csv = $zip->getFromName('zamowienia.csv'); $zip->close();
    check(str_contains($csv, 'A-501') && !str_contains($csv, 'A-502'), 'CSV limited to period');
    $listed = array_column($svc->list(), null, 'file');
    check($listed[$periodFile]['period']['from'] === '2026-09-01', 'Period visible in list');

    // Przywrócenie okresu zastępuje tylko zamówienia z kopii; inne zostają.
    $pdo->exec("UPDATE woo_orders SET status='zmieniony' WHERE woo_order_id IN (501,502)");
    $pdo->exec("INSERT INTO order_messages VALUES (3,501,'później','')");
    $pdo->exec("INSERT INTO woo_orders (id, woo_order_id, payload, date_created) VALUES (4, 504, '{}', '2026-09-20 10:00:00')");
    $r = $svc->restore($periodFile, $pass);
    $status = $pdo->query('SELECT woo_order_id, status FROM woo_orders')->fetchAll(PDO::FETCH_KEY_PAIR);
    check($r['orders'] === 1 && $status[501] === 'new', 'Order from period restored');
    check($status[502] === 'zmieniony' && array_key_exists(504, $status), 'Orders outside period untouched');
    check((int) $pdo->query('SELECT COUNT(*) FROM order_messages WHERE woo_order_id=501')->fetchColumn() === 2, 'Messages of restored order replaced');
    check($svc->verify($svc->path($r['safety']), $pass)['period'] === null, 'Safety copy covers all orders');

    // Wgrywanie: plik z komputera wraca na listę; obce pliki są odrzucane.
    $copy = $temp . '/pobrana.zip'; copy($svc->path($productsFile), $copy);
    $uploaded = $svc->importUpload($copy);
    check($svc->verify($svc->path($uploaded), $pass)['part'] === 'products', 'Uploaded backup accepted');
    $bad = $temp . '/obcy.zip'; $z = new ZipArchive(); $z->open($bad, ZipArchive::CREATE); $z->addFromString('x.txt', 'x'); $z->close();
    rejects(fn() => $svc->importUpload($bad), 'Foreign ZIP rejected on upload');

    // Przywracanie odrzuca uszkodzoną kopię i nic nie zmienia.
    $tampered = $svc->path($productsFile);
    $bytes = file_get_contents($tampered); $pos = intdiv(strlen($bytes), 3); $bytes[$pos] = chr(ord($bytes[$pos]) ^ 0xff);
    file_put_contents($tampered, $bytes);
    $pdo->exec("INSERT INTO products VALUES (2,'SKU-2','Nowa')");
    rejects(fn() => $svc->restore($productsFile, $pass), 'Damaged backup rejected');
    check((int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() === 2, 'Nothing changed after rejected restore');

    $svc->delete($uploaded);
    check(!in_array($uploaded, array_column($svc->list(), 'file'), true), 'Backup deleted');
    check(glob($temp . '/storage/backups/sections/stage-*') === [], 'No unencrypted staging left');
    echo "All $checks section backup checks passed.\n";
} finally {
    rmTree($temp);
}
