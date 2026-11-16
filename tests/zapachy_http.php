<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Rozszerzenie Zapachy przez HTTP: import kopii bazy Aroma Helper (SQL z phpMyAdmin), CSV z Excela (Windows-1250) i strony
// katalogu, ponowny import bez dubli, lista z szukaniem po numerze, nazwie i nucie, karta i formularz (numer miejsca jest
// unikalny), słownik nut, zapach na karcie produktu (propozycja z nazwy, zapis), szukanie w Magazynie po numerze zapachu,
// masowe przypisanie, numer zapachu przy pakowaniu, na karcie zamówienia i liście produkcji, połączenie z olejkiem
// z Produkcji (stan), uprawnienia ról. Baza SQLite.
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'Pase\\')) { require $root . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
    if (str_starts_with($class, 'PaseExt\\Zapachy\\')) { require $root . '/extensions/zapachy/' . substr($class, 16) . '.php'; }
    if (str_starts_with($class, 'PaseExt\\Produkcja\\')) { require $root . '/extensions/produkcja/' . substr($class, 18) . '.php'; }
});
use Pase\Support\PagePermissions;

$checks = 0;
function ok(bool $condition, string $name): void { global $checks; if (!$condition) { throw new RuntimeException('FAIL: ' . $name); } ++$checks; echo "OK: {$name}\n"; }
function client(): CurlHandle {
    $h = curl_init();
    curl_setopt_array($h, [CURLOPT_COOKIEFILE => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 10, CURLOPT_PROXY => '', CURLOPT_HEADER => true]);
    return $h;
}
function request(CurlHandle $h, string $path, ?array $data = null, array $headers = []): array {
    global $base;
    curl_setopt($h, CURLOPT_URL, $base . $path);
    curl_setopt($h, CURLOPT_POST, $data !== null);
    if ($data !== null) {
        $multipart = array_filter($data, static fn($v): bool => $v instanceof CURLFile) !== [];
        curl_setopt($h, CURLOPT_POSTFIELDS, $multipart ? $data : http_build_query($data));
    }
    curl_setopt($h, CURLOPT_HTTPHEADER, $headers);
    $res = curl_exec($h);
    if ($res === false) { throw new RuntimeException(curl_error($h)); }
    $len = curl_getinfo($h, CURLINFO_HEADER_SIZE);
    $body = substr($res, $len);
    return ['status' => curl_getinfo($h, CURLINFO_RESPONSE_CODE), 'headers' => substr($res, 0, $len), 'body' => $body,
            'json' => json_decode($body, true)];
}
function login(string $user): CurlHandle {
    $h = client();
    $page = request($h, 'admin/login.php');
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $page['body'], $m);
    $r = request($h, 'admin/login.php', ['csrf' => $m[1] ?? '', 'username' => $user, 'password' => 'Only-Test-Password-42']);
    if (!in_array($r['status'], [302, 303], true)) { throw new RuntimeException('Login failed for ' . $user); }
    return $h;
}
function csrfOf(string $body): string {
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $body, $m);
    return $m[1] ?? '';
}
function follow(CurlHandle $h, array $r): array {
    if (preg_match('/^Location:\s*(\S+)/mi', $r['headers'], $m)) {
        $loc = $m[1];
        return request($h, str_starts_with($loc, 'http') ? (string) preg_replace('#^https?://[^/]+/#', '', $loc) : 'admin/' . ltrim($loc, '/'));
    }
    return $r;
}

$temp = sys_get_temp_dir() . '/crm-zapachy-http-' . bin2hex(random_bytes(6));
foreach (['public/admin/assets', 'public/pack/assets', 'config', 'storage/security', 'lang', 'extensions'] as $d) { mkdir("$temp/$d", 0700, true); }
$process = null;
try {
    file_put_contents("$temp/.env", "TOTP_REQUIRE_ADMIN=0\nTOTP_KEY_FILE=$temp/storage/security/totp.key\n");
    file_put_contents("$temp/storage/security/totp.key", base64_encode(random_bytes(32)));
    foreach (glob("$root/public/admin/*.php") as $f) { if (!in_array(basename($f), ['bootstrap_admin.php', 'db_admin.php'], true)) { copy($f, "$temp/public/admin/" . basename($f)); } }
    copy("$root/public/admin/assets/loc-picker.js", "$temp/public/admin/assets/loc-picker.js");
    foreach (glob("$root/lang/*.php") as $f) { copy($f, "$temp/lang/" . basename($f)); }
    foreach (glob("$root/public/pack/*.php") as $f) { copy($f, "$temp/public/pack/" . basename($f)); }
    foreach (['packing.js', 'packing.css'] as $f) { copy("$root/public/pack/assets/$f", "$temp/public/pack/assets/$f"); }
    foreach (['zapachy', 'produkcja'] as $ext) { exec('cp -r ' . escapeshellarg("$root/extensions/$ext") . ' ' . escapeshellarg("$temp/extensions/$ext")); }
    file_put_contents("$temp/public/admin/bootstrap_admin.php", '<?php \Pase\Support\PagePermissions::migrate($pdo);');
    $boot = '<?php if (!defined("PASE_ROOT")) { define("PASE_ROOT", ' . var_export($temp, true) . ');'
        . 'spl_autoload_register(static function($c) { if (str_starts_with($c, "Pase\\\\")) require ' . var_export("$root/src/", true)
        . ' . str_replace("\\\\", "/", substr($c, 5)) . ".php"; });'
        . '\Pase\Support\Env::load(PASE_ROOT . "/.env");'
        . 'if (!function_exists("t")) { function t($k, $v = []) { return \Pase\Support\I18n::t($k, $v); } } }'
        . 'return ["db" => [], "allegro" => []];';
    file_put_contents("$temp/config/config.php", $boot);
    file_put_contents("$temp/config/database.php", '<?php return static function ($db) { $pdo = new PDO("sqlite:" . PASE_ROOT . "/test.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); \\Pase\\Support\\Runtime::setPdo($pdo); return $pdo; };');
    file_put_contents("$temp/public/admin/db_admin.php", '<?php $config = require dirname(__DIR__, 2) . "/config/config.php"; $pdo = (require PASE_ROOT . "/config/database.php")($config["db"]);');

    $pdo = new PDO("sqlite:$temp/test.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    \Pase\Support\Runtime::setPdo($pdo);
    $pdo->exec('CREATE TABLE admin_users (id INTEGER PRIMARY KEY, username TEXT, display_name TEXT, role TEXT, password_hash TEXT, is_active INTEGER, session_version INTEGER, totp_secret TEXT, totp_last_step INTEGER DEFAULT -1, totp_recovery_hashes TEXT, last_login TEXT, totp_required INTEGER NOT NULL DEFAULT 0, email TEXT)');
    $ins = $pdo->prepare('INSERT INTO admin_users (id,username,display_name,role,password_hash,is_active,session_version) VALUES (?,?,?,?,?,1,1)');
    foreach ([1 => ['boss', 'Adam', 'admin'], 2 => ['ola', 'Ola', 'editor'], 3 => ['vi', 'Wiktor', 'viewer']] as $id => [$u, $n, $role]) {
        $ins->execute([$id, $u, $n, $role, password_hash('Only-Test-Password-42', PASSWORD_DEFAULT)]);
    }
    PagePermissions::migrate($pdo);
    \Pase\Services\AuditTrail::migrate($pdo);
    $pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
    $pdo->exec('CREATE TABLE order_statuses (id INTEGER PRIMARY KEY, status_key TEXT, label TEXT, color TEXT, position INTEGER, is_system INTEGER DEFAULT 0)');
    $pdo->exec("INSERT INTO order_statuses (status_key,label,color,position) VALUES ('new','Nowe','#8a8f98',1),('processing','W realizacji','#c28a2b',2)");
    $pdo->exec('CREATE TABLE integration_accounts (id INTEGER PRIMARY KEY, type TEXT, name TEXT, is_active INTEGER, config TEXT)');
    $pdo->exec("INSERT INTO integration_accounts (id, type, name, is_active, config) VALUES (1, 'woocommerce', 'Mój Sklep', 1, '{}')");
    $pdo->exec('CREATE TABLE product_mappings (id INTEGER PRIMARY KEY, sku TEXT, allegro_offer_id TEXT)');
    $pdo->exec("CREATE TABLE offer_templates (id INTEGER PRIMARY KEY, product_id INTEGER, marketplace TEXT, data TEXT, status TEXT, external_id TEXT)");
    $pdo->exec('CREATE TABLE product_fields (id INTEGER PRIMARY KEY, field_key TEXT, label TEXT, type TEXT, options TEXT, categories TEXT, position INTEGER DEFAULT 100)');
    $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT, name TEXT, pase_stock INTEGER, pase_price REAL, source_integration_id INTEGER, woo_product_id INTEGER,
        woo_variation_id INTEGER, payload TEXT, imported_at TEXT, updated_at TEXT, description TEXT, short_description TEXT, images TEXT, categories TEXT, attributes TEXT,
        ean TEXT, weight REAL, length REAL, width REAL, height REAL, custom_fields TEXT, allegro_sections TEXT, actual_stock INTEGER NULL, actual_stock_set_at TEXT NULL)');
    $pins = $pdo->prepare('INSERT INTO products (id, sku, name, pase_stock, pase_price, attributes) VALUES (?,?,?,?,?,?)');
    $pins->execute([1, 'GB-1L', 'GINGERBREAD 1l', 5, 49.9, null]);
    $pins->execute([2, 'SC-180', 'Świeca sojowa Sweet Caramel 180 ml', 4, 39.9, null]);
    $pins->execute([3, 'SOJ-180-MW', 'Świeca sojowa 180 ml', 2, 39.9, json_encode([['name' => 'Zapach', 'options' => ['Mulled Wine']]])]);
    $pins->execute([4, 'KNOT-13', 'Knot drewniany 13 mm', 50, 1.5, null]);
    $pdo->exec("CREATE TABLE woo_orders (id INTEGER PRIMARY KEY, woo_order_id INTEGER UNIQUE, integration_id INTEGER, pase_number INTEGER, order_number TEXT, status TEXT,
        pase_status TEXT, currency TEXT, total REAL, customer_name TEXT, customer_email TEXT, date_created TEXT, imported_at TEXT, updated_at TEXT,
        lifecycle TEXT DEFAULT 'active', payload TEXT, local_items TEXT NULL, local_edited_at TEXT NULL, local_billing_email TEXT NULL,
        local_billing_phone TEXT NULL, local_invoice TEXT NULL, local_pickup_point TEXT NULL, forwarded_to TEXT NULL, client_token TEXT NULL,
        automations_done INTEGER DEFAULT 0, internal_status TEXT NULL, source_type TEXT NULL)");
    $pdo->exec('CREATE TABLE shipments (id INTEGER PRIMARY KEY, woo_order_id INTEGER, integration_id INTEGER, courier_code TEXT, waybill_no TEXT, bl_order_id TEXT,
        order_ref TEXT, price REAL, status TEXT, label_link TEXT, request_payload TEXT, response_payload TEXT, created_at TEXT,
        tracking_status TEXT, tracking_code TEXT, tracking_carrier TEXT, tracking_at TEXT, tracking_checked_at TEXT, tracking_events TEXT)');
    $pdo->exec("CREATE TABLE order_messages (id INTEGER PRIMARY KEY, woo_order_id INTEGER, sender TEXT, author_name TEXT, body TEXT,
        attachment_path TEXT, attachment_name TEXT, is_read INTEGER DEFAULT 0, created_at TEXT)");
    $pdo->exec("CREATE TABLE order_documents (id INTEGER PRIMARY KEY, woo_order_id INTEGER, integration_id INTEGER, provider TEXT, document_type TEXT,
        remote_id TEXT, status TEXT DEFAULT 'issued', message TEXT, created_at TEXT, updated_at TEXT)");
    foreach ([\Pase\Services\OrderReturns::class, \Pase\Services\AllegroFeedback::class, \Pase\Services\Customers::class,
        \Pase\Services\DocumentIssueGuard::class, \Pase\Services\OrderMerge::class, \Pase\Services\OrderMargins::class,
        \Pase\Services\Packing::class] as $svc) { $svc::migrate($pdo); }
    $pdo->exec('CREATE TABLE email_templates (id INTEGER PRIMARY KEY, tpl_key TEXT, name TEXT, subject TEXT, body TEXT, is_active INTEGER, mail_account_id INTEGER NULL)');
    $pdo->exec("CREATE TABLE automation_rules (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, event TEXT, is_active INTEGER, priority INTEGER,
        conditions TEXT, actions TEXT, action_type TEXT NULL, action_params TEXT NULL)");
    $woo = static fn(string $name, int $q, string $sku, array $meta = []) => ['name' => $name, 'quantity' => $q, 'sku' => $sku, 'price' => 39.9, 'total' => (string) (39.9 * $q),
        'meta_data' => array_map(static fn($k, $v) => ['key' => 'pa_' . $k, 'display_key' => $k, 'display_value' => $v], array_keys($meta), $meta)];
    $payload = static fn(array $items): string => json_encode(['status' => 'processing', 'date_paid' => '2026-11-14T10:00:00', 'total' => '99.00',
        'billing' => ['first_name' => 'Anna', 'last_name' => 'Kowalska', 'email' => 'anna@example.com', 'address_1' => 'Lipowa 1', 'postcode' => '00-001', 'city' => 'Warszawa'],
        'shipping' => ['first_name' => 'Anna', 'last_name' => 'Kowalska', 'address_1' => 'Lipowa 1', 'postcode' => '00-001', 'city' => 'Warszawa'],
        'line_items' => $items, 'shipping_lines' => [['method_title' => 'Kurier', 'total' => '15.00']]]);
    $oi = $pdo->prepare("INSERT INTO woo_orders (woo_order_id, integration_id, pase_number, order_number, status, pase_status, currency, total, customer_name, customer_email, date_created, imported_at, payload)
        VALUES (?,1,?,?,'processing','new','PLN',99,'Anna Kowalska','anna@example.com','2026-11-14 10:00:00','2026-11-14 10:01:00',?)");
    // 501: wariant „Zapach: Sweet Caramel” na produkcie bez przypisania + piernik po nazwie; 502: produkt z przypisanym zapachem.
    $oi->execute([501, 11, '501', $payload([$woo('Świeca sojowa 180 ml', 2, 'SOJ-180', ['Zapach' => 'Sweet Caramel']), $woo('GINGERBREAD 1l', 1, 'GB-1L'), $woo('Knot drewniany 13 mm', 3, 'KNOT-13')])]);
    $oi->execute([502, 12, '502', $payload([$woo('Świeca sojowa 180 ml', 1, 'SOJ-180-MW')])]);

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $base = 'http://' . $address . '/';
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $address, '-t', "$temp/public"],
        [0 => ['pipe', 'r'], 1 => ['file', "$temp/server.log", 'a'], 2 => ['file', "$temp/server.log", 'a']], $pipes);
    fclose($pipes[0]);
    for ($i = 0; $i < 100; ++$i) { $p = @stream_socket_client('tcp://' . $address, $e1, $e2, 0.1); if ($p) { fclose($p); break; } usleep(10000); }

    $boss = login('boss');
    $ola = login('ola');
    $vi = login('vi');
    $url = static fn(string $page, array $q = []): string => 'admin/ext.php?' . http_build_query(['page' => 'zapachy.' . $page] + $q);
    $post = static function (CurlHandle $h, string $page, array $data, array $q = []) use ($url): array {
        $csrf = csrfOf(request($h, $url('import'))['body']);
        return request($h, $url($page, $q), ['csrf' => $csrf] + $data);
    };
    $upload = static function (CurlHandle $h, string $name, string $content) use ($url, $temp): array {
        $csrf = csrfOf(request($h, $url('import'))['body']);
        $path = "$temp/$name";
        file_put_contents($path, $content);
        return follow($h, request($h, $url('import_zapisz'), ['csrf' => $csrf, 'mode' => 'file', 'file' => new CURLFile($path, 'application/octet-stream', $name)]));
    };
    $vcsrf = static function () use ($vi): string {
        $b = request($vi, 'admin/packing.php')['body'];
        return preg_match('/"csrf":"([a-f0-9]+)"/', $b, $m) ? $m[1] : csrfOf($b);
    };
    $count = static fn(): int => (int) $pdo->query('SELECT COUNT(*) FROM ext_zap_fragrances')->fetchColumn();
    $frag = static fn(string $name): array => $pdo->query('SELECT * FROM ext_zap_fragrances WHERE name = ' . $pdo->quote($name))->fetch(PDO::FETCH_ASSOC) ?: [];

    // ---- Pusto: pierwsze kroki, menu Magazyn
    $page = request($boss, $url('lista'));
    ok($page['status'] === 200 && str_contains($page['body'], 'Dodaj pierwszy zapach') && str_contains($page['body'], 'Import z Aroma Helper'), 'Empty plugin shows first steps');
    ok(str_contains($page['body'], 'page=zapachy.lista'), 'Zapachy in the menu');

    // ---- Import kopii bazy z phpMyAdmin (SQL): zapachy + słownik nut
    $sql = "-- phpMyAdmin SQL Dump\nCREATE TABLE `wpx_aroma_fragrances` (`id` mediumint(9) NOT NULL AUTO_INCREMENT);\n"
        . "INSERT INTO `wpx_aroma_fragrances` (`id`, `producer_name`, `fragrance_number`, `trade_names`, `head_notes`, `heart_notes`, `base_notes`, `head_notes_en`, `heart_notes_en`, `base_notes_en`, `max_concentration`, `ufi_6`, `ufi_8`, `ufi_10`, `stock_amount`, `stock_unit`, `price_per_gram`, `storage_number`, `impressions`, `commercial_description`, `comments`, `created_at`, `updated_at`) VALUES\n"
        . "(3, 'Aromatic Labs', 'AL-118', 'Sweet Caramel, Karmel słony', 'karmel, cukier', 'wanilia, mleko', 'tonka, piżmo', '', '', '', 10.00, 'A1B2-C3D4', '', 'Z9Y8-X7W6', 850.00, 'g', 0.32, '18', '', 'Słodki karmel z nutą \\'masła\\'.\\nIdealny na zimę.', '', '2025-01-01 10:00:00', '2025-01-01 10:00:00'),\n"
        . "(4, 'Aromatic Labs', 'AL-007', 'Gingerbread', 'imbir', 'cynamon, goździki', 'wanilia', '', '', '', 8.00, '', '', '', 0.00, 'g', 0.28, '7', '', '', 'Kupić więcej', '2025-01-01 10:00:00', '2025-01-01 10:00:00'),\n"
        . "(5, 'Candle Co', 'MW', 'Mulled Wine', 'pomarańcza', 'cynamon', 'wino, wanilia', '', '', '', 0.00, '', '', '', 120.00, 'g', 0.00, '12', '', '', NULL, '2025-01-01 10:00:00', '2025-01-01 10:00:00');\n"
        . "INSERT INTO `wpx_aroma_notes` (`id`, `note_name`, `category`) VALUES (1, 'wanilia', 'słodka'), (2, 'karmel', 'słodka'), (3, 'cynamon', 'przyprawowa'), (4, 'pomarańcza', 'cytrusowa');\n";
    $r = $upload($ola, 'kopia.sql', $sql);
    ok(str_contains($r['body'], 'nowe zapachy 3') && $count() === 3, 'SQL dump imports 3 fragrances');
    $sc = $frag('Sweet Caramel');
    ok($sc['storage_no'] === '18' && $sc['aliases'] === 'Karmel słony' && $sc['producer_code'] === 'AL-118' && $sc['head_notes'] === 'karmel, cukier'
        && $sc['base_notes'] === 'tonka, piżmo' && (float) $sc['price_per_g'] === 0.32 && (float) $sc['max_concentration'] === 10.0
        && $sc['ufi'] === '6%: A1B2-C3D4, 10%: Z9Y8-X7W6' && $sc['source_id'] === 'wp:3', 'Fields mapped: number, aliases, notes, producer, price, UFI');
    ok(str_contains($sc['description'], "nutą 'masła'.\nIdealny"), 'Escaped quotes and new lines in SQL are read');
    ok($frag('Mulled Wine')['price_per_g'] === null, 'Zero price stays empty');
    $notes = $pdo->query('SELECT name, category FROM ext_zap_notes')->fetchAll(PDO::FETCH_KEY_PAIR);
    ok(($notes['wanilia'] ?? '') === 'słodka' && ($notes['pomarańcza'] ?? '') === 'cytrusowa' && array_key_exists('imbir', $notes) && $notes['imbir'] === '', 'Note dictionary imported, notes from fragrances added without category');
    $r = $upload($ola, 'kopia.sql', $sql);
    ok(str_contains($r['body'], 'nowe zapachy 0, zaktualizowane 0, bez zmian 3') && $count() === 3, 'Re-import of the same dump changes nothing');

    // ---- CSV z Excela (Windows-1250, średniki, polskie nagłówki): aktualizacja po nazwie + nowy + konflikt numeru
    $csv = iconv('UTF-8', 'Windows-1250', "Numer;Nazwa;Nuty głowy;Nuty serca;Nuty bazy;Opis;Cena za gram\n"
        . "18;Sweet Caramel;;;;;0,35\n21;Biała Herbata;bergamotka;biała herbata, jaśmin;piżmo;Delikatna;\n7;Lawenda;lawenda;;;;\n");
    $r = $upload($ola, 'zapachy.csv', $csv);
    ok(str_contains($r['body'], 'nowe zapachy 2, zaktualizowane 1') && $count() === 5, 'CSV adds new fragrances and updates existing by name');
    ok((float) $frag('Sweet Caramel')['price_per_g'] === 0.35 && $frag('Sweet Caramel')['head_notes'] === 'karmel, cukier', 'Empty CSV cells keep CRM data');
    ok($frag('Biała Herbata')['heart_notes'] === 'biała herbata, jaśmin', 'Windows-1250 text decoded');
    ok($frag('Lawenda')['storage_no'] === '' && str_contains($r['body'], 'numer 7 ma już „Gingerbread”'), 'Taken number is not duplicated, with a warning');

    // ---- Zapisana strona katalogu (shortcode [aroma_zapachy])
    $html = '<html><body><div class="aroma-grid"><div class="aroma-card" data-search="x"><div class="aroma-card-header"><div class="aroma-badges"><span class="aroma-badge">Dostępny</span></div>'
        . '<div class="aroma-storage-no">#33</div></div><h3 class="aroma-card-title">Black Cherry</h3><div class="aroma-notes-preview"><div><strong>Głowa:</strong> wiśnia</div>'
        . '<div><strong>Serce:</strong> migdał</div><div><strong>Baza:</strong> wanilia, drewno</div></div><button class="aroma-toggle-btn">Pokaż opis ▼</button>'
        . '<div class="aroma-description-content"><p>Soczysta wiśnia.</p><p>Na wieczór.</p></div></div></div></body></html>';
    $r = $upload($ola, 'katalog.html', $html);
    $bc = $frag('Black Cherry');
    ok(str_contains($r['body'], 'nowe zapachy 1') && $bc['storage_no'] === '33' && $bc['base_notes'] === 'wanilia, drewno' && $bc['description'] === "Soczysta wiśnia.\n\nNa wieczór.", 'Catalog page imports number, notes and description');
    $r = follow($ola, $post($ola, 'import_zapisz', ['mode' => 'url', 'url' => 'http://127.0.0.1/zapachy']));
    ok(str_contains($r['body'], 'sieci wewnętrznej') && $count() === 6, 'Catalog URL in the local network is refused');

    // ---- Lista: szukanie po numerze, nazwie, nucie i kategorii
    $page = request($vi, $url('lista'));
    ok(str_contains($page['body'], 'Sweet Caramel') && str_contains($page['body'], '>18<') && str_contains($page['body'], 'Najczęstsze nuty'), 'List shows numbers and top notes');
    ok(strpos($page['body'], 'Gingerbread') < strpos($page['body'], 'Mulled Wine') && strpos($page['body'], 'Mulled Wine') < strpos($page['body'], 'Sweet Caramel'), 'List sorted by number (7, 12, 18)');
    $page = request($vi, $url('lista', ['q' => '18']));
    ok(str_contains($page['body'], 'Sweet Caramel') && !str_contains($page['body'], 'Gingerbread') && !str_contains($page['body'], 'zapachy.zapach&amp;id=' . $bc['id']), 'Search by number is exact');
    $page = request($vi, $url('lista', ['q' => 'karmel slony']));
    ok(str_contains($page['body'], 'Sweet Caramel') && !str_contains($page['body'], 'Mulled Wine</a>'), 'Search by other name without Polish letters');
    $page = request($vi, $url('lista', ['nuta' => 'cynamon']));
    ok(str_contains($page['body'], 'Gingerbread</a>') && str_contains($page['body'], 'Mulled Wine</a>') && !str_contains($page['body'], 'Sweet Caramel</a>'), 'Filter by note');
    $page = request($vi, $url('lista', ['kategoria' => 'cytrusowa']));
    ok(str_contains($page['body'], 'Mulled Wine</a>') && !str_contains($page['body'], 'Gingerbread</a>'), 'Filter by note category');

    // ---- Karta, formularz, unikalny numer
    $page = request($vi, $url('zapach', ['id' => $sc['id']]));
    ok(str_contains($page['body'], 'Także: Karmel słony') && str_contains($page['body'], 'Głowa') && str_contains($page['body'], '350,00 zł/kg')
        && str_contains($page['body'], 'Charakter:') && !str_contains($page['body'], 'Edytuj</a>'), 'Card shows notes, price and character; viewer without edit');
    $r = follow($ola, $post($ola, 'zapisz', ['name' => 'Kokos', 'storage_no' => '#18', 'head_notes' => 'kokos']));
    ok(str_contains($r['body'], 'Numer 18 ma już zapach „Sweet Caramel”') && $count() === 6, 'Second fragrance with number 18 refused');
    $r = follow($ola, $post($ola, 'zapisz', ['name' => 'Kokos', 'storage_no' => '40', 'head_notes' => 'Kokos; limonka', 'heart_notes' => '', 'base_notes' => 'wanilia']));
    ok(str_contains($r['body'], 'Dodano zapach: #40 Kokos') && $frag('Kokos')['head_notes'] === 'kokos, limonka', 'New fragrance saved, notes tidied');
    ok($pdo->query("SELECT COUNT(*) FROM ext_zap_notes WHERE name = 'limonka'")->fetchColumn() == 1, 'New notes go to the dictionary');
    $r = request($vi, $url('zapisz'), ['csrf' => $vcsrf(), 'name' => 'Hack', 'storage_no' => '99']);
    ok($r['status'] === 403 && $frag('Hack') === [], 'Viewer cannot add fragrances');

    // ---- Słownik nut
    $r = follow($ola, $post($ola, 'nuty_dodaj', ['bulk' => "limonka, cytrusowa\nróża, kwiatowa\nwanilia, inna\n"]));
    $notes = $pdo->query('SELECT name, category FROM ext_zap_notes')->fetchAll(PDO::FETCH_KEY_PAIR);
    ok(str_contains($r['body'], 'Dodano 2 nut') && $notes['limonka'] === 'cytrusowa' && $notes['róża'] === 'kwiatowa' && $notes['wanilia'] === 'słodka', 'Bulk notes: new + category for empty, existing kept');
    follow($ola, $post($ola, 'nuta_zapisz', ['name' => 'imbir', 'category' => 'Przyprawowa']));
    ok($pdo->query("SELECT category FROM ext_zap_notes WHERE name = 'imbir'")->fetchColumn() === 'przyprawowa', 'Note category edited');
    ok(str_contains(request($vi, $url('nuty'))['body'], 'przyprawowa'), 'Notes page lists categories');

    // ---- Karta produktu: propozycja z nazwy i zapis
    $page = request($ola, 'admin/product_view.php?id=2');
    ok($page['status'] === 200 && str_contains($page['body'], '🌸 Zapach') && str_contains($page['body'], 'Brak przypisanego zapachu')
        && str_contains($page['body'], 'Pasuje po nazwie') && str_contains($page['body'], '#18</b> Sweet Caramel'), 'Product card suggests the fragrance from the name');
    $csrf = csrfOf($page['body']);
    $page = follow($ola, request($ola, $url('produkt_zapisz'), ['csrf' => $csrf, 'product_id' => '2', 'fragrances' => [$sc['id']]]));
    ok(str_contains($page['body'], 'Zapach produktu: #18 Sweet Caramel') && str_contains($page['body'], 'value="' . $sc['id'] . '" checked'), 'Fragrance assigned on the product card');
    ok(preg_match('~data-s="sweet caramel karmel slony" data-no="18"~', $page['body']) === 1 && !preg_match('~data-s="[^"]*tonka~', $page['body']), 'Product card picker searches by name only, not notes');
    $vpage = request($vi, 'admin/product_view.php?id=2');
    ok(str_contains($vpage['body'], 'Sweet Caramel') && !str_contains($vpage['body'], 'zapachy.produkt_zapisz'), 'Viewer sees fragrance without the form');
    $r = request($vi, $url('produkt_zapisz'), ['csrf' => $vcsrf(), 'product_id' => '2', 'fragrances' => []]);
    ok((int) $pdo->query('SELECT COUNT(*) FROM ext_zap_links WHERE product_id = 2')->fetchColumn() === 1 && $r['status'] === 403, 'Viewer cannot change the fragrance');

    // ---- Magazyn: szukanie po numerze zapachu i nucie, plakietka przy nazwie
    $list = request($vi, 'admin/products.php?q=18');
    ok(str_contains($list['body'], 'Świeca sojowa Sweet Caramel 180 ml') && !str_contains($list['body'], 'GINGERBREAD') && str_contains($list['body'], '#18</b> Sweet Caramel'), 'Products search finds by fragrance number, chip at the name');
    ok(str_contains($list['body'], 'SKU / nazwa / zapach'), 'Search box mentions fragrances');
    ok(str_contains(request($vi, 'admin/products.php?q=tonka')['body'], 'Sweet Caramel 180 ml'), 'Products search finds by note');
    ok(str_contains(request($vi, 'admin/products.php?q=GINGER')['body'], 'GINGERBREAD 1l'), 'Normal SKU/name search still works');

    // ---- Przypisz do produktów: propozycje z atrybutu i nazwy
    $page = request($ola, $url('przypisz'));
    $mw = $frag('Mulled Wine');
    $gb = $frag('Gingerbread');
    ok(str_contains($page['body'], 'value="3:' . $mw['id'] . '"') && str_contains($page['body'], 'z atrybutu') && str_contains($page['body'], 'value="1:' . $gb['id'] . '"')
        && !str_contains($page['body'], 'Knot drewniany'), 'Suggestions from attribute and name, nothing for unrelated products');
    $r = follow($ola, request($ola, $url('przypisz_zapisz'), ['csrf' => csrfOf($page['body']), 'pairs' => ['3:' . $mw['id']]]));
    ok(str_contains($r['body'], 'Przypisano zapach do 1 produktu') && (int) $pdo->query("SELECT COUNT(*) FROM ext_zap_links WHERE product_id = 3")->fetchColumn() === 1, 'Selected suggestion assigned');
    $r = request($ola, $url('zapach', ['id' => $gb['id'], 'szukaj' => 'GINGER']));
    ok(str_contains($r['body'], 'name="products[]" value="1"'), 'Product picker on the fragrance card');
    follow($ola, request($ola, $url('przypisz_zapisz'), ['csrf' => csrfOf($r['body']), 'fragrance_id' => $gb['id'], 'back' => 'zapach', 'products' => ['1']]));
    ok(str_contains(request($vi, $url('zapach', ['id' => $gb['id']]))['body'], 'Produkty z tym zapachem (1)'), 'Fragrance card lists the linked product');

    // ---- Pakowanie: numer przy pozycji (przypisany, z wariantu)
    $pack = request($ola, 'admin/packing.php');
    ok(str_contains($pack['body'], 'zapachy') && str_contains($pack['body'], 'packing.js'), 'Packing screen loads the extension script');
    $o = request($ola, 'admin/packing_api.php?action=order&id=501')['json'];
    $z = $o['order']['ext']['zapachy'] ?? [];
    $idx = [];
    foreach ($o['order']['items'] as $it) { $idx[$it['sku']] = (string) $it['idx']; }
    ok(($z[$idx['SOJ-180']][0]['no'] ?? '') === '18' && ($z[$idx['GB-1L']][0]['no'] ?? '') === '7' && !isset($z[$idx['KNOT-13']]), 'Packing: number from the variant and from the linked product, none for a wick');

    // ---- Karta zamówienia
    $view = request($vi, 'admin/order_view.php?id=501');
    ok($view['status'] === 200 && str_contains($view['body'], '🌸 Zapachy do zrobienia') && str_contains($view['body'], '#18</b> Sweet Caramel') && str_contains($view['body'], '#7</b> Gingerbread'), 'Order card lists fragrance numbers');

    // ---- Lista produkcji
    $pl = request($vi, 'admin/production_list.php?ids=501,502');
    ok(str_contains($pl['body'], 'Nr zapachu: <b>18 · Sweet Caramel</b>') && str_contains($pl['body'], 'Nr zapachu: <b>12 · Mulled Wine</b>') && str_contains($pl['body'], 'Nr zapachu: <b>7 · Gingerbread</b>'), 'Production list shows fragrance numbers');

    // ---- Tylko przypisane (ustawienie): wariant i nazwa nie zgadują
    $pdo->prepare("INSERT OR REPLACE INTO settings (setting_key, setting_value) VALUES ('EXT_ZAPACHY', ?)")->execute([json_encode(['guess' => 'no'])]);
    $o = request($ola, 'admin/packing_api.php?action=order&id=501')['json'];
    $z = $o['order']['ext']['zapachy'] ?? [];
    ok(!isset($z[$idx['SOJ-180']]) && ($z[$idx['GB-1L']][0]['no'] ?? '') === '7', 'Setting "only assigned": variant guess off, assigned product still shown');
    $pdo->exec("DELETE FROM settings WHERE setting_key = 'EXT_ZAPACHY'");

    // ---- Produkcja: olejek połączony po nazwie, stan przy zapachu i numer przy recepturze
    $ps = new \PaseExt\Produkcja\Store($pdo);
    $oil = $ps->saveMaterial(null, ['name' => 'Olejek Sweet Caramel', 'category' => 'zapach', 'unit' => 'g', 'min_stock' => '500']);
    $ps->addDelivery((string) $oil['id'], ['qty' => '420'], 'test');
    $r = follow($ola, $post($ola, 'polacz_produkcja', []));
    ok(str_contains($r['body'], 'Połączono 1 zapachów') && $frag('Sweet Caramel')['material_id'] === $oil['id'], 'Production oil linked by name');
    $page = request($vi, $url('lista'));
    ok(str_contains($page['body'], 'zp-stock low') && str_contains($page['body'], '420'), 'List shows oil stock from Produkcja, low below minimum');
    $wosk = $ps->saveMaterial(null, ['name' => 'Wosk sojowy', 'category' => 'wosk', 'unit' => 'g']);
    $ps->saveProduct(null, ['name' => 'Świeca karmelowa', 'sku' => 'KAR-1', 'recipe' => [['material_id' => $wosk['id'], 'qty' => 150], ['material_id' => $oil['id'], 'qty' => 12]]]);
    $oi->execute([503, 13, '503', $payload([$woo('Świeca karmelowa', 1, 'KAR-1')])]);
    $pl = request($vi, 'admin/production_list.php?ids=503');
    ok(str_contains($pl['body'], 'Zapach: <b>Olejek Sweet Caramel</b>') && str_contains($pl['body'], 'Nr zapachu: <b>18 · Sweet Caramel</b>'), 'Recipe oil shows its fragrance number on the production list');

    // ---- Eksport CSV i powrót tym samym plikiem (przypisania po SKU)
    $exp = request($vi, $url('eksport'));
    ok($exp['status'] === 200 && str_contains($exp['headers'], 'text/csv') && str_starts_with($exp['body'], "\xEF\xBB\xBFnumer;nazwa;")
        && str_contains($exp['body'], '18;"Sweet Caramel";"Karmel słony"') && str_contains($exp['body'], ';0,35;10;') && str_contains($exp['body'], 'SC-180'), 'Export CSV: header, number, aliases, price, product SKU');
    $nexp = request($vi, $url('eksport_nuty'));
    ok(str_contains($nexp['body'], 'nuta;kategoria') && str_contains($nexp['body'], 'wanilia;słodka'), 'Notes export');
    ok(!str_contains(request($vi, $url('lista'))['body'], 'zapachy.import') && str_contains(request($vi, $url('lista'))['body'], 'zapachy.eksport'), 'Viewer gets export, not import');
    $pdo->exec('DELETE FROM ext_zap_links WHERE product_id = 2');
    $r = $upload($ola, 'zapachy-eksport.csv', substr($exp['body'], 3));
    ok(str_contains($r['body'], 'nowe zapachy 0') && str_contains($r['body'], 'Przypisano do produktów: 1') && $count() === 7
        && (int) $pdo->query('SELECT COUNT(*) FROM ext_zap_links WHERE product_id = 2')->fetchColumn() === 1, 'Re-import of the export restores links by SKU, no duplicates');

    // ---- Archiwum i usuwanie
    follow($ola, $post($ola, 'archiwizuj', ['id' => $frag('Kokos')['id'], 'archive' => '1']));
    ok(!str_contains(request($vi, $url('lista'))['body'], 'Kokos</a>') && str_contains(request($vi, $url('lista', ['archiwum' => 1]))['body'], 'Kokos</a>'), 'Archived fragrance moves to Archive');
    follow($ola, $post($ola, 'usun', ['id' => $mw['id']]));
    ok($frag('Mulled Wine') === [] && (int) $pdo->query('SELECT COUNT(*) FROM ext_zap_links WHERE product_id = 3')->fetchColumn() === 0, 'Delete removes the fragrance and its links');

    // ---- Strona Wtyczki: uprawnienia
    $plugins = request($boss, 'admin/plugins.php');
    ok(str_contains($plugins['body'], 'Zapachy') && str_contains($plugins['body'], 'Karta produktu') && str_contains($plugins['body'], 'Lista produkcji'), 'Plugins page lists the new permissions');

    // ---- Lista: podział na strony (50 na stronie) i szukanie na żywo
    $insP = $pdo->prepare("INSERT INTO ext_zap_fragrances (id, storage_no, name, aliases, producer, producer_code, head_notes, heart_notes, base_notes, description, comments, ufi, source_id, archived, created_at, updated_at) VALUES (?, ?, ?, '', '', '', 'testowa', '', '', '', '', '', '', 0, '2026-11-16', '2026-11-16')");
    for ($i = 1; $i <= 60; $i++) { $insP->execute(['pg' . $i, (string) (500 + $i), sprintf('Strona Test %02d', $i)]); }
    $p1 = request($vi, $url('lista'));
    $p2 = request($vi, $url('lista', ['strona' => 2]));
    ok(str_contains($p1['body'], ' z 6') && str_contains($p1['body'], 'zapachy.lista&amp;strona=2') && !str_contains($p1['body'], 'Strona Test 60</a>')
        && str_contains($p2['body'], 'Strona Test 60</a>') && !str_contains($p2['body'], 'Gingerbread</a>'), 'List is paged, 50 per page');
    $all = request($vi, $url('lista', ['na_stronie' => 0]));
    $p100 = request($vi, $url('lista', ['na_stronie' => 25, 'strona' => 3]));
    ok(str_contains($all['body'], 'Gingerbread</a>') && str_contains($all['body'], 'Strona Test 60</a>') && str_contains($p100['body'], '51–'), 'Per-page choice: all and 25');
    $pq = request($vi, $url('lista', ['q' => 'strona test 5', 'strona' => 9]));
    ok(str_contains($pq['body'], 'Strona Test 59</a>') && str_contains($pq['body'], 'id="zp-results"') && str_contains($pq['body'], 'zp-filter'), 'Search narrows pages; results block for live search');
    $pdo->exec("DELETE FROM ext_zap_fragrances WHERE id LIKE 'pg%'");

    $warnings = preg_grep('/PHP (Warning|Fatal|Parse|Deprecated|Notice)|Rozszerzenie .* błąd/', file("$temp/server.log") ?: []);
    ok($warnings === [], 'No PHP warnings or extension errors' . ($warnings ? ': ' . implode(' | ', array_map('trim', $warnings)) : ''));
    echo "PASS: {$checks} Zapachy HTTP checks\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if (getenv('KEEP_TEMP')) { echo "temp: $temp\n"; } else { exec('rm -rf ' . escapeshellarg($temp)); }
}
