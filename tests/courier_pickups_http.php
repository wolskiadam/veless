<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Statystyki → Odbiory kurierów: rozpoznanie odbioru przez kuriera w historii śledzenia (InPost po polsku,
// Allegro One po angielsku, przybliżenie z „nadana”), okno godzin i „wrzuć do”, grupowanie po metodzie dostawy,
// strona panelu na SQLite (bez sieci), menu i uprawnienia.
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'Pase\\')) { require $root . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Services\CourierPickupStats as Stats;
use Pase\Support\PagePermissions;

$checks = 0;
function ok(bool $condition, string $name): void { global $checks; if (!$condition) { throw new RuntimeException('FAIL: ' . $name); } ++$checks; echo "OK: {$name}\n"; }

// --- Rozpoznanie odbioru ---
$inpost = [
    ['at' => '2026-11-03T08:00:00+01:00', 'code' => 'PENDING', 'desc' => 'Przygotowana przez nadawcę'],
    ['at' => '2026-11-03T09:40:00+01:00', 'code' => 'IN_TRANSIT', 'desc' => 'Paczka nadana w automacie'],
    ['at' => '2026-11-03T11:20:00+01:00', 'code' => 'IN_TRANSIT', 'desc' => 'Odebrana od nadawcy'],
    ['at' => '2026-11-03T18:00:00+01:00', 'code' => 'IN_TRANSIT', 'desc' => 'Przyjęta w sortowni'],
];
$p = Stats::pickupOf($inpost);
ok($p !== null && $p['exact'] && $p['at']->format('H:i') === '11:20' && $p['dropoff']?->format('H:i') === '09:40', 'InPost: pickup = Odebrana od nadawcy, drop-off remembered');

// Allegro One: angielskie opisy, czas w UTC -> Europe/Warsaw (listopad = UTC+1).
$one = [
    ['at' => '2026-11-04T07:10:00Z', 'code' => 'PENDING', 'desc' => 'Parcel has been created'],
    ['at' => '2026-11-04T09:55:00Z', 'code' => 'PENDING', 'desc' => 'Parcel has been picked up from the parcel locker by the courier'],
    ['at' => '2026-11-04T15:00:00Z', 'code' => 'IN_TRANSIT', 'desc' => 'Parcel has been dispatched to its destination point'],
];
$p = Stats::pickupOf($one);
ok($p !== null && $p['exact'] && $p['at']->format('H:i') === '10:55', 'Allegro One English pickup recognised, converted to Polish time');

// Przewoźnik bez zdarzenia odbioru: pierwsze „nadana” po oczekiwaniu (w przybliżeniu), nie sortownia.
$p = Stats::pickupOf([
    ['at' => '2026-11-05T08:00:00+01:00', 'code' => 'PENDING', 'desc' => 'Przesyłka przygotowana do nadania'],
    ['at' => '2026-11-05T12:05:00+01:00', 'code' => 'PENDING', 'desc' => 'Parcel has been sent'],
    ['at' => '2026-11-05T20:00:00+01:00', 'code' => 'IN_TRANSIT', 'desc' => 'Przyjęta w sortowni'],
]);
ok($p !== null && !$p['exact'] && $p['at']->format('H:i') === '12:05', 'No pickup event: first "sent" event used as approximation');
ok(Stats::pickupOf([
    ['at' => '2026-11-05T08:00:00+01:00', 'code' => 'PENDING', 'desc' => 'Przesyłka utworzona'],
    ['at' => '2026-11-06T06:00:00+01:00', 'code' => 'IN_TRANSIT', 'desc' => 'Przyjęta w sortowni'],
]) === null, 'History starting at sorting centre gives no pickup (no guessing)');
ok(Stats::pickupOf([['at' => '2026-11-05T08:00:00+01:00', 'code' => 'PENDING', 'desc' => 'Przesyłka utworzona']]) === null, 'Waiting parcel has no pickup');
ok(Stats::pickupOf([['at' => '2026-11-05T08:00:00Z', 'code' => 'MANUAL', 'desc' => 'W drodze — ustawiono ręcznie']]) === null, 'Manual status is ignored');

// --- Okno godzin ---
$w = Stats::window([11 * 60 + 2, 11 * 60 + 10, 11 * 60 + 15, 11 * 60 + 20, 11 * 60 + 30, 11 * 60 + 41, 11 * 60 + 50, 12 * 60 + 5, 10 * 60 + 35, 13 * 60]);
ok($w['n'] === 10 && Stats::hm($w['from']) === '11:05' && Stats::hm($w['to']) === '11:55' && Stats::hm($w['drop_by']) === '10:45', 'Window 20–80 percentile, drop-by floored to quarter: ' . Stats::hm($w['from']) . '–' . Stats::hm($w['to']) . ' / ' . Stats::hm($w['drop_by']));
ok(Stats::window([630])['drop_by'] === 630 && Stats::hm(630) === '10:30', 'Single pickup window');

// --- Strona przez HTTP ---
function client(): CurlHandle {
    $h = curl_init();
    curl_setopt_array($h, [CURLOPT_COOKIEFILE => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10, CURLOPT_PROXY => '', CURLOPT_HEADER => false]);
    return $h;
}
function request(CurlHandle $h, string $path, ?array $data = null): array {
    global $base;
    curl_setopt($h, CURLOPT_URL, $base . $path);
    curl_setopt($h, CURLOPT_POST, $data !== null);
    if ($data !== null) { curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($data)); } else { curl_setopt($h, CURLOPT_HTTPGET, true); }
    $res = curl_exec($h);
    if ($res === false) { throw new RuntimeException(curl_error($h)); }
    return ['status' => curl_getinfo($h, CURLINFO_RESPONSE_CODE), 'body' => (string) $res];
}
function login(string $user): CurlHandle {
    $h = client();
    $page = request($h, 'admin/login.php');
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $page['body'], $m);
    request($h, 'admin/login.php', ['csrf' => $m[1] ?? '', 'username' => $user, 'password' => 'Only-Test-Password-42']);
    return $h;
}

$temp = sys_get_temp_dir() . '/crm-pickups-http-' . bin2hex(random_bytes(6));
foreach (['public/admin/assets', 'config', 'storage/security', 'storage/logs', 'lang'] as $d) { mkdir("$temp/$d", 0700, true); }
$process = null;
try {
    file_put_contents("$temp/.env", "TOTP_REQUIRE_ADMIN=0\n");
    foreach (glob("$root/public/admin/*.php") as $f) { if (!in_array(basename($f), ['bootstrap_admin.php', 'db_admin.php'], true)) { copy($f, "$temp/public/admin/" . basename($f)); } }
    foreach (glob("$root/public/admin/assets/*") as $f) { if (is_file($f)) { copy($f, "$temp/public/admin/assets/" . basename($f)); } }
    foreach (glob("$root/lang/*.php") as $f) { copy($f, "$temp/lang/" . basename($f)); }
    file_put_contents("$temp/public/admin/bootstrap_admin.php", '<?php \Pase\Support\PagePermissions::migrate($pdo);');
    $boot = '<?php if (!defined("PASE_ROOT")) { define("PASE_ROOT", ' . var_export($temp, true) . ');'
        . 'date_default_timezone_set("Europe/Warsaw");'
        . 'spl_autoload_register(static function($c) { if (str_starts_with($c, "Pase\\\\")) require ' . var_export("$root/src/", true)
        . ' . str_replace("\\\\", "/", substr($c, 5)) . ".php"; });'
        . '\Pase\Support\Env::load(PASE_ROOT . "/.env");'
        . 'if (!function_exists("t")) { function t($k, $v = []) { return \Pase\Support\I18n::t($k, $v); } } }'
        . 'return ["db" => [], "allegro" => []];';
    file_put_contents("$temp/config/config.php", $boot);
    file_put_contents("$temp/config/database.php", '<?php return static fn($db) => new PDO("sqlite:" . PASE_ROOT . "/test.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);');
    file_put_contents("$temp/public/admin/db_admin.php", '<?php $config = require dirname(__DIR__, 2) . "/config/config.php"; $pdo = (require PASE_ROOT . "/config/database.php")($config["db"]);');

    $pdo = new PDO("sqlite:$temp/test.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE admin_users (id INTEGER PRIMARY KEY, username TEXT, display_name TEXT, role TEXT, password_hash TEXT, is_active INTEGER, session_version INTEGER, totp_secret TEXT, totp_last_step INTEGER DEFAULT -1, totp_recovery_hashes TEXT, last_login TEXT, totp_required INTEGER NOT NULL DEFAULT 0, email TEXT)');
    $ins = $pdo->prepare('INSERT INTO admin_users (id,username,display_name,role,password_hash,is_active,session_version) VALUES (?,?,?,?,?,1,1)');
    foreach ([1 => ['boss', 'Adam', 'admin'], 3 => ['vi', 'Wiktor', 'viewer']] as $id => [$u, $n, $role]) {
        $ins->execute([$id, $u, $n, $role, password_hash('Only-Test-Password-42', PASSWORD_DEFAULT)]);
    }
    PagePermissions::migrate($pdo);
    \Pase\Services\AuditTrail::migrate($pdo);
    $pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
    $pdo->exec('CREATE TABLE woo_orders (id INTEGER PRIMARY KEY, woo_order_id INTEGER, payload TEXT)');
    $pdo->exec('CREATE TABLE shipments (id INTEGER PRIMARY KEY, woo_order_id INTEGER, courier_code TEXT, waybill_no TEXT, status TEXT,
        tracking_status TEXT, tracking_carrier TEXT, tracking_events TEXT, created_at TEXT)');

    // Dane: Allegro One Box - kurier ok. 11–12 (So wcześniej), InPost Paczkomat - po 15, DPD bez metody w zamówieniu.
    $tz = new DateTimeZone('Europe/Warsaw');
    $order = $pdo->prepare('INSERT INTO woo_orders (woo_order_id, payload) VALUES (?, ?)');
    $ship = $pdo->prepare("INSERT INTO shipments (woo_order_id, courier_code, waybill_no, status, tracking_status, tracking_carrier, tracking_events, created_at) VALUES (?,?,?,'created','in_transit',?,?,?)");
    $id = 100;
    mt_srand(7);
    for ($day = 1; $day <= 40; $day++) {
        $date = (new DateTimeImmutable("-$day days", $tz))->setTime(0, 0);
        $dow = (int) $date->format('N');
        if ($dow === 7) { continue; }
        // Allegro One Box (angielskie opisy, czas UTC)
        foreach (range(1, 2) as $k) {
            $mins = $dow === 6 ? mt_rand(9 * 60 + 30, 10 * 60 + 20) : mt_rand(11 * 60, 12 * 60 + 5);
            $drop = $date->setTime(intdiv($mins - 90, 60), ($mins - 90) % 60)->setTimezone(new DateTimeZone('UTC'));
            $pick = $date->setTime(intdiv($mins, 60), $mins % 60)->setTimezone(new DateTimeZone('UTC'));
            $order->execute([++$id, json_encode(['delivery' => ['method' => ['name' => 'Allegro One Box, One Kurier']]])]);
            $ship->execute([$id, 'Allegro · ALLEGRO', 'A' . $id, 'ALLEGRO', json_encode([
                ['at' => $drop->format('Y-m-d\TH:i:s\Z'), 'code' => 'PENDING', 'desc' => 'Parcel has been dropped off at the One Box by the sender'],
                ['at' => $pick->format('Y-m-d\TH:i:s\Z'), 'code' => 'IN_TRANSIT', 'desc' => 'Parcel has been picked up from the One Box by the courier'],
                ['at' => $pick->modify('+6 hours')->format('Y-m-d\TH:i:s\Z'), 'code' => 'IN_TRANSIT', 'desc' => 'Parcel has been accepted at the sorting center'],
            ]), $date->format('Y-m-d 08:00:00')]);
        }
        // InPost Paczkomat (Woo, polskie opisy)
        if ($dow <= 5 && $day % 2 === 0) {
            $mins = mt_rand(15 * 60, 16 * 60 + 30);
            $order->execute([++$id, json_encode(['shipping_lines' => [['method_title' => 'Paczkomaty InPost']]])]);
            $ship->execute([$id, 'InPost', 'I' . $id, 'INPOST', json_encode([
                ['at' => $date->setTime(9, 0)->format(DATE_ATOM), 'code' => 'PENDING', 'desc' => 'Przygotowana przez nadawcę'],
                ['at' => $date->setTime(intdiv($mins, 60), $mins % 60)->format(DATE_ATOM), 'code' => 'IN_TRANSIT', 'desc' => 'Odebrana od nadawcy'],
            ]), $date->format('Y-m-d 08:00:00')]);
        }
    }
    // DPD bez zamówienia (nazwa z przesyłki), 3 odbiory - mało danych.
    foreach ([2, 3, 4] as $day) {
        $date = (new DateTimeImmutable("-$day days", $tz));
        $ship->execute([null, 'Kurier DPD', 'D' . $day, 'DPD', json_encode([
            ['at' => $date->setTime(8, 0)->format(DATE_ATOM), 'code' => 'PENDING', 'desc' => 'Zarejestrowano dane przesyłki'],
            ['at' => $date->setTime(14, 10 + $day)->format(DATE_ATOM), 'code' => 'IN_TRANSIT', 'desc' => 'Przesyłka odebrana przez Kuriera'],
        ]), $date->format('Y-m-d 08:00:00')]);
    }
    // Stara przesyłka poza okresem - nie liczy się.
    $ship->execute([null, 'Stary kurier', 'OLD', 'DPD', json_encode([['at' => '2025-01-02T10:00:00+01:00', 'code' => 'IN_TRANSIT', 'desc' => 'Przesyłka odebrana przez Kuriera']]), '2025-01-02 08:00:00']);

    date_default_timezone_set('Europe/Warsaw');
    $report = (new Stats($pdo))->report(date('Y-m-d', strtotime('-89 days')));
    $names = array_column($report, 'name');
    ok($names === ['Allegro One Box, One Kurier', 'Paczkomaty InPost', 'Kurier DPD'], 'Grouped by delivery method, most used first: ' . implode(' | ', $names));
    $box = $report[0];
    ok($box['from'] >= 11 * 60 - 60 && $box['to'] <= 12 * 60 + 10 && $box['exact'] === $box['count'], 'One Box window around 11–12: ' . Stats::hm($box['from']) . '–' . Stats::hm($box['to']));
    ok($box['scope'] === 'Pn–Pt' && $box['drop_by'] >= 10 * 60 + 45, 'Headline uses weekdays, Saturday does not pull drop-by down: ' . Stats::hm($box['drop_by']));
    ok(isset($box['days'][6]) && $box['days'][6]['to'] <= 10 * 60 + 25 && !isset($box['days'][7]), 'Saturday has its own earlier window, no Sunday');
    ok($box['dropoff']['same_day'] === $box['count'] && $box['dropoff']['latest_same_day'] !== null, 'Drop-off at One Box counted as same-day pickup');
    ok($report[2]['count'] === 3 && $report[2]['carriers'] === ['DPD'], 'Shipment without order uses courier name; old shipment skipped');

    $free = static function (): string { $s = stream_socket_server('tcp://127.0.0.1:0'); $a = stream_socket_get_name($s, false); fclose($s); return $a; };
    $address = $free();
    $base = 'http://' . $address . '/';
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $address, '-t', "$temp/public"],
        [0 => ['pipe', 'r'], 1 => ['file', "$temp/server.log", 'a'], 2 => ['file', "$temp/server.log", 'a']], $pipes);
    fclose($pipes[0]);
    for ($i = 0; $i < 100; ++$i) { $s = @stream_socket_client('tcp://' . $address, $e1, $e2, 0.1); if ($s) { fclose($s); break; } usleep(10000); }

    $boss = login('boss');
    $page = request($boss, 'admin/courier_pickups.php');
    ok($page['status'] === 200 && str_contains($page['body'], 'Allegro One Box, One Kurier') && str_contains($page['body'], 'Paczkomaty InPost'), 'Page shows services');
    ok(str_contains($page['body'], 'Kurier odbiera zwykle') && str_contains($page['body'], 'Wrzuć do'), 'Page shows window and drop-by time');
    ok(str_contains($page['body'], 'Mało danych'), 'Few pickups flagged as approximate');
    ok(str_contains($page['body'], 'href="courier_pickups.php"'), 'Page is in the menu');
    $stats = request($boss, 'admin/courier_pickups.php?range=30');
    ok($stats['status'] === 200 && str_contains($stats['body'], '<option value="30" selected>'), 'Range filter');

    $vi = login('vi');
    ok(PagePermissions::keyForFile('courier_pickups.php') === 'statistics', 'Same page permission as Statistics');
    ok(request($vi, 'admin/courier_pickups.php')['status'] === 200, 'Viewer sees it like Statistics');

    $log = (string) @file_get_contents("$temp/server.log");
    ok(!preg_match('/PHP (Fatal|Warning|Notice|Deprecated)/', $log), 'No PHP errors or warnings in the server log');
    echo "All $checks checks passed\n";
    if (getenv('KEEP_SERVER')) { echo "server: $base temp: $temp\n"; fwrite(STDERR, "ready\n"); sleep((int) getenv('KEEP_SERVER')); }
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if (getenv('KEEP_TEMP')) { echo "temp: $temp\n"; } else { exec('rm -rf ' . escapeshellarg($temp)); }
}
