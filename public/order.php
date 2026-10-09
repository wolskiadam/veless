<?php
declare(strict_types=1);

/**
 * PUBLICZNA strona zamówienia dla klienta (bez logowania, dostęp przez token).
 * URL: order.php?token=… — po wejściu klient potwierdza tożsamość (e-mail/telefon/
 * lub telefon z zamówienia). Dopiero potem widzi szczegóły, śledzenie i wątek.
 *
 * Token jest długi i losowy (woo_orders.client_token). Weryfikacja zapamiętana w sesji
 * (do zamknięcia przeglądarki). Limit: 5 prób / 15 min na token (anty-zgadywanie).
 */

use Pase\Repository\OrderMessageRepository;
use Pase\Repository\OrderStatusRepository;
use Pase\Repository\ProductRepository;
use Pase\Repository\SettingsRepository;
use Pase\Repository\ShipmentRepository;
use Pase\Repository\WooOrderRepository;
use Pase\Services\ClientVerify;
use Pase\Services\OrderMessageService;

$config = require dirname(__DIR__) . '/config/config.php';
\Pase\Support\Logger::toFile(PASE_ROOT . '/storage/app.log');
$pdo = (require PASE_ROOT . '/config/database.php')($config['db']);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['pub_csrf'])) {
    $_SESSION['pub_csrf'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['pub_csrf'];

$token = (string) ($_GET['token'] ?? '');
$orderRepo = new WooOrderRepository($pdo);
$order = $orderRepo->findByClientToken($token);

// --- Język strony klienta ---
// Priorytet: jawny wybór (?lang=, zapamiętany w sesji) -> przeglądarka (Accept-Language).
$langKey = 'order_lang_' . substr($token, 0, 16);
if (isset($_GET['lang']) && \Pase\Support\I18n::isAvailable((string) $_GET['lang'])) {
    $_SESSION[$langKey] = preg_replace('/[^a-z]/', '', strtolower((string) $_GET['lang']));
}
$locale = $_SESSION[$langKey]
    ?? \Pase\Support\I18n::detect(null, $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null);
\Pase\Support\I18n::setLocale($locale);

$settings = new SettingsRepository($pdo);
// Wejście przez plik-pośrednik na domenie sklepu (np. twojsklep.pl/zamowienie.php): linki budujemy
// na jego adres, a IP klienta bierzemy z nagłówka pośrednika. Bez pośrednika - zwykłe order.php.
$proxy    = \Pase\Services\ClientLinks::proxyContext($settings);
$selfUrl  = $proxy['self'] ?? 'order.php';
$fileUrl  = $proxy !== null ? $proxy['self'] . '?_file=1&' : 'order_attachment.php?';
$clientIp = $proxy['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
// Kolory strony wg sklepu, z którego jest zamówienie (tło, przyciski).
$GLOBALS['clientThemeCss'] = $order ? (new \Pase\Services\ClientLinks($pdo))->themeCss((int) ($order['integration_id'] ?? 0)) : '';
// Nazwa sklepu, z którego jest zamówienie (nadawca jego konta e-mail).
$shopName = (new \Pase\Services\MailAccounts($pdo))->shopName($order ? (int) $order['woo_order_id'] : null, 'Sklep');
$e = static fn($v) => htmlspecialchars((string) $v);

/** Renderuje minimalny dokument HTML (nagłówek/stopka wspólne dla bramki i strony). */
function pageShell(string $title, string $shopName, string $bodyHtml): void
{
    $e = static fn($v) => htmlspecialchars((string) $v);
    ?><!doctype html>
<html lang="<?= $e(\Pase\Support\I18n::locale()) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $e($title) ?> — <?= $e($shopName) ?></title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@400;500;600&display=swap">
<style>
    :root {
        --bg:#f6f5f2; --surface:#ffffff; --line:#e6e3da;
        --ink:#22252b; --ink-2:#6d7076; --ink-3:#a1a3a8;
        --accent:#9c6b2e; --accent-hover:#835a24; --accent-soft:#f2e6d0; --accent-ink:#5a3c14;
        --success-bg:#e3f1e9; --success-ink:#276a49;
        --danger-bg:#fbe6e1; --danger-ink:#a3341f;
        --muted-bg:#eef0ee;
        --radius:14px; --radius-sm:9px;
        --shadow:0 1px 2px rgba(30,25,15,.04), 0 6px 18px -10px rgba(30,25,15,.12);
        --font-ui:'Plus Jakarta Sans', -apple-system, "Segoe UI", Roboto, sans-serif;
        --font-num:'IBM Plex Mono', ui-monospace, monospace;
    }
    * { box-sizing:border-box; }
    body { font-family:var(--font-ui); background:var(--bg); color:var(--ink); margin:0; padding:0; }
    .wrap { max-width:820px; margin:0 auto; padding:32px 14px 40px; }
    .card { background:var(--surface); border-radius:var(--radius); box-shadow:var(--shadow); padding:20px 22px; margin-bottom:16px; }
    h1 { font-size:22px; font-weight:800; letter-spacing:-.01em; margin:0 0 4px; }
    h2 { font-size:15px; margin:0 0 14px; text-transform:uppercase; letter-spacing:.03em; color:var(--ink-2); }
    .muted { color:var(--ink-2); font-size:13px; }
    .pill { display:inline-block; padding:4px 14px; border-radius:999px; color:#fff; font-weight:600; font-size:14px; }
    table { width:100%; border-collapse:collapse; }
    th,td { text-align:left; padding:10px 6px; border-bottom:1px solid var(--line); font-size:14px; vertical-align:middle; }
    th { color:var(--ink-2); font-weight:600; font-size:12px; }
    .flash { padding:12px 14px; border-radius:10px; margin-bottom:16px; font-size:14px; }
    .flash.ok { background:var(--success-bg); color:var(--success-ink); } .flash.err { background:var(--danger-bg); color:var(--danger-ink); }
    input[type=text],input[type=email],input[type=tel],textarea,select { width:100%; padding:10px 12px; border:1px solid var(--line); border-radius:var(--radius-sm); font-family:inherit; font-size:14px; color:var(--ink); }
    input:focus,textarea:focus,select:focus { outline:none; border-color:var(--accent); box-shadow:0 0 0 3px var(--accent-soft); }
    .btn { background:var(--accent); color:var(--accent-on, #fff); border:0; border-radius:var(--radius-sm); padding:11px 20px; font-size:15px; font-weight:700; font-family:inherit; cursor:pointer; transition:background .15s; }
    .btn:hover { background:var(--accent-hover); }
    a { color:var(--accent); }
    .prod-link { color:var(--ink); text-decoration:none; }
    .prod-link:hover { color:var(--accent); text-decoration:underline; }
    /* Timeline statusu */
    .steps { display:flex; gap:0; margin-top:6px; }
    .deliv-steps { margin-top:14px; }
    .planned-ship { margin:4px 0 0; font-weight:600; font-size:15px; color:var(--ink); }
    /* Status dostawy - mapa */
    .deliv-stage { margin-bottom:12px; }
    .deliv-stage-label { display:inline-block; padding:5px 14px; border-radius:999px; background:var(--accent-soft); color:var(--accent-ink); font-weight:600; font-size:14px; }
    /* Status dostawy - panel "Informacje" */
    .deliv-info { margin-top:18px; }
    .di-row { display:flex; gap:14px; padding:7px 0; border-bottom:1px solid var(--line); font-size:14px; align-items:flex-start; }
    .di-row:last-child { border-bottom:0; }
    .di-label { flex:0 0 150px; color:var(--ink-2); }
    .di-value { flex:1; color:var(--ink); word-break:break-word; }
    .di-bar { position:relative; height:24px; border-radius:6px; background:var(--muted-bg); overflow:hidden; }
    .di-bar-fill { position:absolute; inset:0 auto 0 0; background:var(--accent); opacity:.55; }
    .di-bar-text { position:relative; display:flex; align-items:center; height:100%; padding:0 12px; font-size:13px; color:var(--ink); font-weight:600; }
    .di-track { align-items:center; border-bottom:0; padding-top:12px; }
    .di-waybill { flex:1; font-size:14px; font-family:var(--font-num); letter-spacing:.02em; }
    .di-track-btn { flex:0 0 auto; display:inline-block; padding:8px 16px; border:1px solid var(--line); border-radius:8px; color:var(--ink); text-decoration:none; font-size:13px; font-weight:600; background:var(--surface); }
    .di-track-btn:hover { border-color:var(--accent); color:var(--accent-ink); }
    .step { flex:1; text-align:center; position:relative; font-size:12px; color:var(--ink-3); }
    .step::before { content:''; position:absolute; top:9px; left:-50%; width:100%; height:3px; background:var(--line); z-index:0; }
    .step:first-child::before { display:none; }
    .step .dot { width:20px; height:20px; border-radius:50%; background:var(--line); margin:0 auto 6px; position:relative; z-index:1; }
    .step.done .dot, .step.current .dot { background:var(--accent); }
    .step.done::before, .step.current::before { background:var(--accent); }
    .step.current { color:var(--accent-ink); font-weight:700; }
    .step.current.negative { color:#b3261e; }
    .step.current.negative .dot { background:#c0392b; }
    /* Produkty */
    .prod { display:flex; align-items:center; gap:12px; }
    .prod img, .prod .noimg { width:48px; height:48px; border-radius:8px; object-fit:cover; background:var(--muted-bg); flex:0 0 auto; }
    /* Wątek */
    .thread { display:flex; flex-direction:column; gap:10px; margin:12px 0; }
    .bubble { max-width:82%; padding:9px 13px; border-radius:12px; font-size:14px; white-space:pre-wrap; word-break:break-word; }
    .bubble .meta { font-size:11px; color:var(--ink-2); margin-bottom:3px; }
    .msg-del { background:none; border:0; color:var(--danger-ink); font-size:11px; cursor:pointer; padding:0 0 0 6px; text-decoration:underline; }
    .msg-del:hover { color:#7a2717; }
    .from-staff { align-self:flex-end; background:var(--accent-soft); }
    /* Historia i wiadomości */
    .hist { margin:26px 0 16px; }
    .hist-head { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:8px; }
    .hist-title { font-size:22px; font-weight:600; text-transform:none; letter-spacing:-.01em; color:var(--ink); margin:0; }
    .hist-day { font-size:15px; font-weight:700; color:var(--ink-2); margin:18px 0 10px; }
    .hist-card { padding:8px 22px; }
    .hist-row { display:flex; gap:22px; align-items:flex-start; padding:12px 0; border-bottom:1px solid var(--line); font-size:15px; }
    .hist-row:last-child { border-bottom:0; }
    .hist-time { flex:0 0 44px; color:var(--ink); font-family:var(--font-num); font-size:14px; padding-top:1px; }
    .hist-msg .bubble { max-width:100%; flex:1; background:var(--bg); white-space:normal; }
    .hist-msg .bubble .meta { display:flex; gap:6px; align-items:center; }
    .hist-msg .bubble.from-staff { background:var(--accent-soft); }
    .bubble a { color:var(--accent); text-decoration:underline; text-underline-offset:2px; word-break:break-all; }
    .btn-outline { background:var(--surface); border:1.5px solid var(--line); color:var(--ink); border-radius:999px; padding:9px 18px; font-weight:700; font-size:14px; font-family:inherit; cursor:pointer; }
    .btn-outline:hover { border-color:var(--accent); color:var(--accent); }
    .mail-subject { font-weight:700; }
    .mail-body { margin-top:6px; }
    .hist-locked { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; font-size:15px; margin:14px 0 4px; }
    .lock-card { text-align:center; padding:34px 24px; }
    .lock-card h3 { font-size:20px; margin:10px 0 8px; }
    .lock-card p { max-width:560px; margin:0 auto 18px; font-size:14px; line-height:1.55; }
    .lock-ico { font-size:36px; color:var(--ink-2); }
    .verify-form { display:flex; gap:8px; max-width:420px; margin:0 auto; }
    .verify-form input { flex:1; }
    .verify-form[hidden], #msgNew[hidden] { display:none; }
    @media (max-width:560px){ .verify-form{flex-direction:column} .hist-row{gap:12px} }
    .from-client { align-self:flex-start; background:var(--muted-bg); }
</style>
<?php if (!empty($GLOBALS['clientThemeCss'])): /* kolory sklepu (Konfiguracja → Strona klienta) */ ?>
<style><?= $GLOBALS['clientThemeCss'] ?></style>
<?php endif; ?>
</head>
<body>
<div class="wrap"><?= $bodyHtml ?></div>
</body></html><?php
}

// --- Token nieprawidłowy ---
if ($order === null) {
    http_response_code(404);
    pageShell(t('client.not_found.title'), $shopName,
        '<div class="card" style="text-align:center"><h1>' . $e(t('client.not_found.title')) . '</h1>'
        . '<p class="muted">' . $e(t('client.not_found.text')) . '</p></div>');
    exit;
}

$wooOrderId = (int) $order['woo_order_id'];
$payload    = json_decode($order['payload'] ?? '{}', true) ?: [];

// Doprecyzuj język z kraju zamówienia, jeśli klient nie wybrał jawnie i przeglądarka
// nie wskazała dostępnego języka (np. kraj DE -> de). Mapowanie proste kraj->język.
if (!isset($_SESSION[$langKey]) && \Pase\Support\I18n::locale() === \Pase\Support\I18n::DEFAULT) {
    $country = strtolower((string) ($payload['billing']['country'] ?? ''));
    $byCountry = ['gb' => 'en', 'us' => 'en', 'ie' => 'en', 'de' => 'de', 'at' => 'de', 'ua' => 'uk'];
    if (isset($byCountry[$country]) && \Pase\Support\I18n::isAvailable($byCountry[$country])) {
        \Pase\Support\I18n::setLocale($byCountry[$country]);
    }
}

// ===== BRAMKA WERYFIKACJI =====
// Weryfikację można globalnie wyłączyć w Konfiguracji → E-mail (domyślnie włączona).
$verifyEnabled = ($settings->get('CLIENT_VERIFY_ENABLED', '1') ?? '1') !== '0';
$availFields   = ClientVerify::availableFields($payload);

$verifiedKey = 'order_verified_' . $wooOrderId;
// Gdy weryfikacja wyłączona lub brak danych do weryfikacji - wpuszczamy od razu.
$isVerified  = !empty($_SESSION[$verifiedKey]) || !$verifyEnabled || $availFields === [];

// Pomocniczy licznik komunikatów; właściwy limit współdzielony jest w RateLimiter.
$rlKey = 'verify_rl_' . $wooOrderId;
$rl = $_SESSION[$rlKey] ?? ['n' => 0, 't' => 0];
$WINDOW = 900; // 15 min
$MAXTRY = 5;
if ($rl['t'] > 0 && (time() - $rl['t']) > $WINDOW) {
    $rl = ['n' => 0, 't' => 0]; // okno minęło - reset
}
$blocked = $rl['n'] >= $MAXTRY;

if (!$isVerified && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify') {
    if (!hash_equals($CSRF, (string) ($_POST['csrf'] ?? ''))) {
        $verifyErr = t('client.verify.session');
    } elseif ($blocked || !(new \Pase\Support\RateLimiter(PASE_ROOT . '/storage/security/client-verify.json'))->consume([
        'token:' . $token => [5, 900],
        'ip:' . $clientIp => [50, 900],
    ])) {
        $blocked = true;
        $verifyErr = t('client.verify.toomany');
    } else {
        // Klient wpisuje jedną informację; system sam rozpoznaje, czy to e-mail czy telefon.
        $value = (string) ($_POST['verify_value'] ?? '');
        if (ClientVerify::matchesAny($value, $payload)) {
            $_SESSION[$verifiedKey] = true;
            $isVerified = true;
            unset($_SESSION[$rlKey]);
            // PRG: przeładuj na czysty GET.
            header('Location: ' . $selfUrl . '?token=' . urlencode($token) . '#wiadomosci');
            exit;
        }
        $rl = ['n' => $rl['n'] + 1, 't' => $rl['t'] ?: time()];
        $_SESSION[$rlKey] = $rl;
        $left = max(0, $MAXTRY - $rl['n']);
        $verifyErr = t('client.verify.mismatch') . ' '
            . ($left ? t('client.verify.left', ['n' => $left]) : t('client.verify.later'));
        $blocked = $rl['n'] >= $MAXTRY;
    }
}

// Strona zamówienia jest widoczna od razu (status, produkty, dostawa, historia). Potwierdzenia
// tożsamości wymagają tylko WIADOMOŚCI - formularz „Odblokuj dostęp" jest w sekcji wiadomości.

// ===== KLIENT ZWERYFIKOWANY — pełna strona =====
$msgRepo = new OrderMessageRepository($pdo);
$flashOk = $flashErr = null;

if ($isVerified && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'client_message') {
    if (!hash_equals($CSRF, (string) ($_POST['csrf'] ?? ''))) {
        $flashErr = t('client.verify.session');
    } else {
        $body = trim($_POST['message_body'] ?? '');
        $name = trim($_POST['author_name'] ?? '');
        if ($body === '' && empty($_FILES['attachment']['name'])) {
            $flashErr = t('client.msg.need');
        } else {
            try {
                $svc = new OrderMessageService($pdo, $msgRepo, $settings);
                $file = !empty($_FILES['attachment']['name']) ? $_FILES['attachment'] : null;
                $svc->fromClient($order, $body, $name, $file);
                $flashOk = t('client.msg.sent');
            } catch (\RuntimeException $ex) {
                $flashErr = $ex->getMessage();
            }
        }
    }
}

// Usunięcie WŁASNEJ wiadomości klienta (tylko sender='client', w obrębie tego zamówienia).
if ($isVerified && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_message') {
    if (!hash_equals($CSRF, (string) ($_POST['csrf'] ?? ''))) {
        $flashErr = t('client.verify.session');
    } else {
        $deleted = $msgRepo->deleteClientMessage((int) ($_POST['message_id'] ?? 0), $wooOrderId);
        if ($deleted !== null) {
            // Skasuj plik załącznika, jeśli był.
            if (!empty($deleted['attachment_path'])) {
                $full = \Pase\Services\Attachments::absolutePath($deleted['attachment_path']);
                if ($full !== null && is_file($full)) {
                    @unlink($full);
                }
            }
            $flashOk = t('client.msg.deleted');
        } else {
            $flashErr = t('client.msg.delete_fail');
        }
    }
}

$thread    = $isVerified ? $msgRepo->thread($wooOrderId) : [];
// Maile z automatyzacji (np. „Realizujemy Twoje zamówienie") - klient je dostał, więc widzi je w historii.
// Tylko wysłane; treść z dziennika, a przy starszych mailach odtworzona z szablonu (AutoMailText).
$autoMails = [];
try {
    $autoMails = array_values(array_filter((new \Pase\Services\EmailLog($pdo))->forOrder($wooOrderId),
        static fn(array $m): bool => ($m['status'] ?? '') === 'sent'));
} catch (\Throwable) {
    // starsza baza bez dziennika e-maili
}
// Przed potwierdzeniem tożsamości klient widzi tylko, ile wiadomości czeka (bez treści).
$lockedCount = $isVerified ? 0 : count($msgRepo->thread($wooOrderId)) + count($autoMails);
if (!$isVerified) {
    $autoMails = [];
}
$statusMap = (new OrderStatusRepository($pdo))->map();
$statusAll = (new OrderStatusRepository($pdo))->all();
$shipments = (new ShipmentRepository($pdo))->forOrder($wooOrderId);

// --- Czas: daty z bazy (strefa serwera MySQL) i z API (UTC) pokazujemy w czasie polskim ---
$tzPl = new DateTimeZone('Europe/Warsaw');
try {
    $dbOffset = (int) $pdo->query('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())')->fetchColumn();
} catch (\Throwable $ex) {
    $dbOffset = 0;
}
/** Znacznik czasu: $utc = true dla dat UTC (API, audyt), false dla CURRENT_TIMESTAMP z bazy. */
$toTs = static function (?string $v, bool $utc) use ($dbOffset): ?int {
    if ($v === null || trim($v) === '') {
        return null;
    }
    $hasTz = (bool) preg_match('/(Z|[+-]\d\d:?\d\d)$/', trim($v));
    $ts = strtotime($hasTz ? $v : $v . ' UTC');
    if ($ts === false) {
        return null;
    }
    return ($hasTz || $utc) ? $ts : $ts - $dbOffset;
};
$fmtDate = static function (?int $ts, string $fmt = 'd.m.Y H:i') use ($tzPl): string {
    return $ts === null ? '' : (new DateTime('@' . $ts))->setTimezone($tzPl)->format($fmt);
};
$orderTs = $toTs((string) ($order['date_created'] ?? ($payload['date_created'] ?? '')), true);

// --- Historia zamówienia dla klienta: przyjęcie, zmiany statusu, nadanie i etapy przesyłki, wiadomości ---
$history = [];
if ($orderTs !== null) {
    $history[] = ['ts' => $orderTs, 'type' => 'event', 'text' => t('client.history.created')];
}
try {
    $au = $pdo->prepare("SELECT after_json, created_at FROM audit_events WHERE order_id = ? AND action = 'order.status_changed' ORDER BY id");
    $au->execute([$wooOrderId]);
    $stMap = (new OrderStatusRepository($pdo))->map();
    foreach ($au->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $st = (string) ((json_decode((string) $a['after_json'], true) ?: [])['pase_status'] ?? '');
        if ($st !== '' && ($ts = $toTs((string) $a['created_at'], true)) !== null) {
            $history[] = ['ts' => $ts, 'type' => 'event', 'text' => t('client.history.status', ['status' => $stMap[$st]['label'] ?? $st])];
        }
    }
} catch (\Throwable $ex) {
    // brak tabeli historii (starsza baza) - pomijamy
}
foreach ($shipments as $sh) {
    if (in_array($sh['status'] ?? '', ['cancelled', 'error'], true)) {
        continue;
    }
    $cn = \Pase\Support\CarrierLabel::forClient((string) ($sh['courier_code'] ?? ''));
    $cn = $cn === '—' ? '' : $cn;
    if (($ts = $toTs((string) ($sh['created_at'] ?? ''), false)) !== null) {
        $history[] = ['ts' => $ts, 'type' => 'event', 'text' => t('client.history.shipped', ['carrier' => $cn, 'waybill' => (string) ($sh['waybill_no'] ?? '')])];
    }
    foreach (json_decode((string) ($sh['tracking_events'] ?? ''), true) ?: [] as $ev) {
        $evDesc = \Pase\Support\TrackingText::display((string) ($ev['desc'] ?? ''), (string) ($ev['code'] ?? ''), \Pase\Support\I18n::locale());
        if (($ts = $toTs((string) ($ev['at'] ?? ''), true)) !== null && $evDesc !== '') {
            $history[] = ['ts' => $ts, 'type' => 'event', 'text' => t('client.history.parcel', ['desc' => $evDesc])];
        }
    }
}
foreach ($thread as $m) {
    if (($ts = $toTs((string) ($m['created_at'] ?? ''), false)) !== null) {
        $history[] = ['ts' => $ts, 'type' => 'message', 'm' => $m];
    }
}
$mailText = new \Pase\Services\AutoMailText($pdo);
foreach ($autoMails as $am) {
    if (($ts = $toTs((string) ($am['created_at'] ?? ''), true)) !== null) {
        $history[] = ['ts' => $ts, 'type' => 'message', 'm' => [
            'id' => 0, 'sender' => 'auto', 'subject' => (string) $am['subject'], 'body' => $mailText->forEntry($am)['text'],
        ]];
    }
}
usort($history, static fn($a, $b) => $b['ts'] <=> $a['ts']);
$historyByDay = [];
foreach ($history as $h) {
    $historyByDay[$fmtDate($h['ts'], 'Y-m-d')][] = $h;
}

// Które boxy są włączone dla klienta (Konfiguracja → Strona klienta).
$box = \Pase\Services\ClientPageBoxes::fromSettings($settings->all());

// Etapy dostawy wyprowadzone z REALNYCH danych (bez udawania śledzenia u kuriera):
//  Przyjęte (zawsze) -> W realizacji (status processing) -> Nadana (jest przesyłka) -> Doręczona
//  (śledzenie u przewoźnika: delivered; bez śledzenia - status shipped lub dalszy).
$paseStatus = (string) ($order['pase_status'] ?? '');
$hasShipment = array_filter($shipments, static fn($s) => ($s['status'] ?? '') !== 'cancelled') !== [];
$deliveryCancelled = $paseStatus === 'cancelled';
// Indeks aktualnego etapu (0..3); -1 dla anulowanego (osobny komunikat).
// Statusy „negatywne" nie są etapem realizacji - pokazujemy je tylko, gdy zamówienie w nich jest.
$negativeStatuses = ['cancelled', 'refunded', 'failed'];
$posOf = static fn(string $key): ?int => isset($statusMap[$key]['position']) ? (int) $statusMap[$key]['position'] : null;
$curStatusPos = $posOf($paseStatus);
$shippedPos   = $posOf('shipped');
// Status dalszy niż „Wysłane" (np. własny „Zrealizowano") też znaczy, że zamówienie dotarło.
$pastShipped  = $paseStatus === 'shipped' || ($curStatusPos !== null && $shippedPos !== null
    && $curStatusPos > $shippedPos && !in_array($paseStatus, $negativeStatuses, true));

// Planowana data nadania ustawiona na karcie zamówienia - pokazujemy do czasu nadania paczki.
$plannedShip = \Pase\Services\PlannedShipDate::forClient($order, $hasShipment, $pastShipped,
    (new DateTime('now', new DateTimeZone('Europe/Warsaw')))->format('Y-m-d'));

// Czy zamówienie w ogóle ma dostawę fizyczną? Subskrypcje, dostępy i inne produkty wirtualne
// nie mają metody wysyłki ani paczki - wtedy nie pokazujemy etapów dostawy ani adresu.
$needsShipping = $shipments !== []
    || !empty($payload['shipping_lines'])
    || !empty($payload['delivery']['method']['id'])
    || !empty($payload['delivery']['pickupPoint']);

// Pierwsza aktywna przesyłka (numer listu / kurier / cena) do panelu "Informacje".
$ship = null;
foreach ($shipments as $s) {
    if (($s['status'] ?? '') !== 'cancelled') { $ship = $s; break; }
}

// Etap dostawy: etap ze śledzenia u przewoźnika ma pierwszeństwo przed statusem zamówienia
// („Wysłane" nie znaczy „Doręczona", dopóki kurier tego nie potwierdzi).
$progress = \Pase\Support\DeliveryProgress::compute(
    in_array($paseStatus, ['processing', 'shipped'], true),
    $hasShipment,
    $pastShipped,
    $ship['tracking_status'] ?? null
);
$deliveryStage = $progress['stage'];
$deliverySteps = [
    t('client.delivery.accepted'),
    t('client.delivery.processing'),
    t('client.delivery.shipped'),
    t('client.delivery.delivered'),
];
// Procent postępu (do paska) wg etapu - spójny ze znacznikiem na mapie.
$deliveryPercent = $progress['percent'];
// Etykieta bieżącego etapu: przed doręczeniem - etap ze śledzenia (np. „W drodze"), jeśli jest.
$deliveryLabel = $progress['tracking'] !== null
    ? \Pase\Services\ShipmentTracking::label($progress['tracking'])
    : ($deliverySteps[$deliveryStage] ?? '');

/** Buduje URL śledzenia u przewoźnika wg kodu kuriera i numeru listu (lub null). */
$trackingUrl = static function (?string $courier, ?string $waybill): ?string {
    $waybill = trim((string) $waybill);
    if ($waybill === '') { return null; }
    $c = strtolower((string) $courier);
    if (str_contains($c, 'inpost') || str_contains($c, 'paczkomat')) {
        return 'https://inpost.pl/sledzenie-przesylek?number=' . rawurlencode($waybill);
    }
    if (str_contains($c, 'dpd'))   { return 'https://tracktrace.dpd.com.pl/parcelDetails?p1=' . rawurlencode($waybill); }
    if (str_contains($c, 'gls'))   { return 'https://gls-group.com/PL/pl/sledzenie-paczek?match=' . rawurlencode($waybill); }
    if (str_contains($c, 'dhl'))   { return 'https://www.dhl.com/pl-pl/home/sledzenie.html?tracking-id=' . rawurlencode($waybill); }
    if (str_contains($c, 'pocz'))  { return 'https://emonitoring.poczta-polska.pl/?numer=' . rawurlencode($waybill); }
    // Fallback: ogólna wyszukiwarka śledzenia (uniwersalna).
    return 'https://parcelsapp.com/pl/tracking/' . rawurlencode($waybill);
};

$items    = $payload['line_items'] ?? [];
$currency = $payload['currency'] ?? ($order['currency'] ?? 'PLN');
$shipping = $payload['shipping'] ?? [];
$billing  = $payload['billing'] ?? [];
$hasShip  = trim(($shipping['address_1'] ?? '') . ($shipping['city'] ?? '')) !== '';
$addr     = $hasShip ? $shipping : $billing;

// Miniatury produktów z magazynu (po SKU) - jeśli zaimportowane.
$prodRepo = new ProductRepository($pdo);
$thumbs = [];
foreach ($items as $it) {
    $sku = (string) ($it['sku'] ?? '');
    if ($sku === '' || isset($thumbs[$sku])) {
        continue;
    }
    $p = $prodRepo->findBySku($sku);
    $imgs = $p ? (json_decode($p['images'] ?? '[]', true) ?: []) : [];
    $first = $imgs[0] ?? null;
    $first = is_array($first) ? ($first['src'] ?? null) : $first;
    // Poza magazynem: zdjęcie oferty Allegro zapisane w pozycji (patrz OrderItemThumbnails).
    $thumbs[$sku] = $first ?: (is_string($it['image'] ?? null) ? $it['image'] : null);
}

// Link do produktu w sklepie: oferta Allegro, strona produktu Woo (permalink z magazynu)
// albo adres sklepu z ID produktu (?post_type=product&p=ID - Woo przekierowuje na właściwą stronę).
$shopBase = '';
if (!empty($order['integration_id'])) {
    $srcAcc = (new \Pase\Repository\IntegrationAccountRepository($pdo))->find((int) $order['integration_id']);
    if ($srcAcc !== null && $srcAcc['type'] === 'woocommerce') {
        $shopBase = rtrim((string) ($srcAcc['config']['base_url'] ?? ''), '/');
    }
}
$productUrl = static function (array $it) use ($prodRepo, $shopBase): ?string {
    if (!empty($it['allegro_offer_id'])) {
        return 'https://allegro.pl/oferta/' . rawurlencode((string) $it['allegro_offer_id']);
    }
    if ($shopBase === '') {
        return null;
    }
    $sku = (string) ($it['sku'] ?? '');
    $p = $sku !== '' ? $prodRepo->findBySku($sku) : null;
    $link = $p ? (json_decode((string) ($p['payload'] ?? ''), true)['permalink'] ?? null) : null;
    if (is_string($link) && str_starts_with($link, $shopBase)) {
        return $link;
    }
    $pid = (int) ($it['product_id'] ?? 0);
    return $pid > 0 ? $shopBase . '/?post_type=product&p=' . $pid : null;
};

$cur = $statusMap[$order['pase_status'] ?? ''] ?? null;
$statusLabel = $cur['label'] ?? ($order['pase_status'] ?: '—');
$statusColor = $cur['color'] ?? '#888888';
$curPos = $cur['position'] ?? null;

$orderLabel = $order['pase_number'] !== null ? '#' . (int) $order['pase_number'] : ('nr ' . ($order['order_number'] ?? $wooOrderId));
$activeShip = array_filter($shipments, static fn($s) => ($s['status'] ?? '') !== 'cancelled');

// Kolejność boxów ustawiona przez administratora (Konfiguracja → Strona klienta).
$boxOrder = \Pase\Services\ClientPageBoxes::order($settings->all());

ob_start();
?>
    <!-- Nagłówek zamówienia (zawsze na górze, nie podlega kolejności boxów) -->
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
            <div>
                <h1 style="margin-bottom:2px"><?= $e(t('client.order', ['order' => $orderLabel])) ?></h1>
                <p class="muted"><?= $e($fmtDate($orderTs)) ?></p>
            </div>
            <span class="pill" style="background:<?= $e($statusColor) ?>"><?= $e($statusLabel) ?></span>
        </div>
    </div>

    <?php if ($flashOk): ?><div class="flash ok"><?= $e($flashOk) ?></div><?php endif; ?>
    <?php if ($flashErr): ?><div class="flash err"><?= $e($flashErr) ?></div><?php endif; ?>

<?php
// --- Każdy box do osobnego bufora; wypisujemy je niżej wg $boxOrder. ---
$boxHtml = [];

// Box: status (pasek kroków statusu zamówienia)
ob_start();
// Pasek kroków: tylko droga „do przodu" (Nowe → W realizacji → Wysłane) plus bieżący status,
// jeśli to własny status (np. „Zrealizowano"). Anulowane / Zwrot pokazujemy wyłącznie wtedy,
// gdy zamówienie faktycznie ma ten status - wtedy jako ostatni krok zamiast dalszej drogi.
// Zamówienie bez wysyłki (subskrypcja, produkt wirtualny) nie ma kroku „Wysłane".
$curKey = (string) ($order['pase_status'] ?? '');
$sys = array_values(array_filter($statusAll, static function ($s) use ($curKey, $negativeStatuses, $needsShipping) {
    $k = (string) ($s['status_key'] ?? '');
    if ($k === $curKey) { return true; }
    if ((int) ($s['is_system'] ?? 0) !== 1 || in_array($k, $negativeStatuses, true)) { return false; }
    return $needsShipping || $k !== 'shipped';
}));
usort($sys, static fn($a, $b) => (int) $a['position'] <=> (int) $b['position']);
if (in_array($curKey, $negativeStatuses, true)) {
    // Zwrot: zamówienie przeszło całą drogę, a na końcu jest zwrot.
    // Anulowane / nieudane: nie wiemy, jak daleko doszło - pokazujemy „Nowe" → ten status.
    $neg  = array_values(array_filter($sys, static fn($s) => (string) $s['status_key'] === $curKey));
    $rest = array_values(array_filter($sys, static fn($s) => (string) $s['status_key'] !== $curKey
        && ($curKey === 'refunded' || (string) $s['status_key'] === 'new')));
    $sys  = array_merge($rest, $neg);
}
$curIdx = null;
foreach ($sys as $i => $st) { if ((string) $st['status_key'] === $curKey) { $curIdx = $i; } }
if ($box['status'] && $sys && $curIdx !== null):
?>
    <div class="card">
        <div class="steps">
            <?php foreach ($sys as $i => $st):
                $cls = $i < $curIdx ? 'done' : ($i === $curIdx ? 'current' : '');
                if ($i === $curIdx && in_array($curKey, $negativeStatuses, true)) { $cls .= ' negative'; }
            ?>
                <div class="step <?= $cls ?>"><div class="dot"></div><?= $e($st['label']) ?></div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif;
$boxHtml['status'] = ob_get_clean();

// Nazwa kuriera bez dopisków technicznych („(ręcznie)" = wpisane ręcznie w CRM - klienta to nie dotyczy).
$courierName = static fn(array $sh): string => \Pase\Support\CarrierLabel::forClient((string) ($sh['courier_code'] ?? ''));
// Status przesyłki po ludzku: etap ze śledzenia u przewoźnika, a bez niego stan nadania.
$shipStatus = static function (array $sh): string {
    $trk = (string) ($sh['tracking_status'] ?? '');
    if ($trk !== '' && \Pase\Services\ShipmentTracking::label($trk) !== '') {
        return \Pase\Services\ShipmentTracking::label($trk);
    }
    return match ((string) ($sh['status'] ?? '')) {
        'pending'   => 'w przygotowaniu',
        'cancelled' => 'anulowana',
        'error'     => 'w przygotowaniu',
        default     => 'nadana',
    };
};

// Box: delivery_status (informacje o dostawie)
ob_start();
?>
    <!-- Status dostawy: etap + informacje o przesyłce -->
    <?php if ($box['delivery_status'] && $needsShipping): ?>
    <div class="card">
        <h2><?= $e(t('client.delivery_status')) ?></h2>
        <?php if ($deliveryCancelled): ?>
            <span class="pill" style="background:var(--danger-ink)"><?= $e(t('client.delivery.cancelled')) ?></span>
        <?php else: ?>
            <div class="deliv-stage">
                <span class="deliv-stage-label"><?= $e($deliveryLabel) ?></span>
            </div>
            <?php if ($plannedShip !== null): ?>
            <p class="planned-ship">🚚 <?= $e(t('client.planned_ship', ['date' => date('d.m.Y', strtotime($plannedShip))])) ?></p>
            <?php endif; ?>

            <!-- Informacje o przesyłce -->
            <div class="deliv-info">
                <div class="di-row">
                    <span class="di-label"><?= $e(t('client.delivery.current_status')) ?></span>
                    <span class="di-value"><strong><?= $e($deliveryLabel) ?></strong></span>
                </div>
                <div class="di-row">
                    <span class="di-label"><?= $e(t('client.delivery.order_no')) ?></span>
                    <span class="di-value"><?= $e($order['pase_number'] !== null ? (int) $order['pase_number'] : ($order['order_number'] ?? $wooOrderId)) ?></span>
                </div>
                <?php if ($orderTs !== null): ?>
                <div class="di-row">
                    <span class="di-label"><?= $e(t('client.delivery.sale_date')) ?></span>
                    <span class="di-value"><?= $e($fmtDate($orderTs)) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($ship !== null): ?>
                <div class="di-row">
                    <span class="di-label"><?= $e(t('client.delivery.shipment')) ?></span>
                    <span class="di-value">
                        <?php /* Bez ceny przesyłki - koszt wysyłki to dana wewnętrzna. */ ?>
                        <?= $e($courierName($ship)) ?>
                    </span>
                </div>
                <?php endif; ?>
                <div class="di-row">
                    <span class="di-label"><?= $e(t('client.delivery.parcels')) ?></span>
                    <span class="di-value" style="width:100%">
                        <div class="di-bar"><div class="di-bar-fill" style="width:<?= (int) $deliveryPercent ?>%"></div>
                            <span class="di-bar-text">🚚 <?= (int) $deliveryPercent ?>% – <?= $e($deliveryLabel) ?></span>
                        </div>
                    </span>
                </div>
                <?php if ($ship !== null && trim((string) ($ship['waybill_no'] ?? '')) !== ''):
                    $turl = $trackingUrl($ship['courier_code'] ?? null, $ship['waybill_no'] ?? null); ?>
                <div class="di-row di-track">
                    <span class="di-waybill muted"><?= $e($ship['waybill_no']) ?></span>
                    <?php if ($turl !== null): ?>
                        <a class="di-track-btn" href="<?= $e($turl) ?>" target="_blank" rel="noopener noreferrer"><?= $e(t('client.delivery.track')) ?></a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; /* box delivery_status */
$boxHtml['delivery_status'] = ob_get_clean();

// Box: products
ob_start(); ?>
    <!-- Produkty -->
    <?php if ($box['products']): ?>
    <div class="card">
        <h2><?= $e(t('client.products')) ?></h2>
        <table>
            <?php foreach ($items as $it): $sku = (string) ($it['sku'] ?? ''); $img = $thumbs[$sku] ?? null; ?>
                <tr>
                    <td>
                        <div class="prod">
                            <?php if ($img): ?><img src="<?= $e($img) ?>" alt=""><?php else: ?><div class="noimg"></div><?php endif; ?>
                            <div>
                                <?php $purl = $productUrl($it); ?>
                                <?php if ($purl !== null): ?>
                                    <a class="prod-link" href="<?= $e($purl) ?>" target="_blank" rel="noopener"><strong><?= $e($it['name'] ?? '') ?></strong></a>
                                <?php else: ?><strong><?= $e($it['name'] ?? '') ?></strong><?php endif; ?>
                                <?php if ($sku !== ''): ?><div class="muted"><?= $e($sku) ?></div><?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td style="white-space:nowrap"><?= $e(t('client.qty', ['n' => (int) ($it['quantity'] ?? 0)])) ?></td>
                    <td style="text-align:right;white-space:nowrap"><?= $e(number_format((float) ($it['total'] ?? 0), 2)) ?> <?= $e($currency) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr><td colspan="2" style="text-align:right;font-weight:700;border:0;padding-top:14px"><?= $e(t('client.total')) ?></td>
                <td style="text-align:right;font-weight:700;border:0;padding-top:14px"><?= $e(number_format((float) ($payload['total'] ?? 0), 2)) ?> <?= $e($currency) ?></td></tr>
        </table>
    </div>
    <?php endif; /* box products */
$boxHtml['products'] = ob_get_clean();

// Box: delivery + tracking (wspólna karta; pozycja sterowana kluczem 'delivery')
ob_start(); ?>
    <!-- Dostawa -->
    <?php if (($box['delivery'] && $needsShipping) || ($box['tracking'] && $activeShip)): ?>
    <div class="card">
        <?php if ($box['delivery'] && $needsShipping): ?>
        <h2><?= $e(t('client.delivery')) ?></h2>
        <p style="line-height:1.6;margin:0">
            <?php
            $lines = array_filter([
                trim(($addr['first_name'] ?? '') . ' ' . ($addr['last_name'] ?? '')),
                $addr['company'] ?? '',
                trim(($addr['address_1'] ?? '') . ' ' . ($addr['address_2'] ?? '')),
                trim(($addr['postcode'] ?? '') . ' ' . ($addr['city'] ?? '')),
                $addr['country'] ?? '',
            ]);
            echo implode('<br>', array_map($e, $lines)) ?: '<span class="muted">—</span>';
            ?>
        </p>
        <?php endif; ?>
        <?php if ($box['tracking'] && $activeShip): ?>
            <table style="margin-top:14px">
                <tr><th><?= $e(t('client.tracking.no')) ?></th><th><?= $e(t('client.tracking.carrier')) ?></th><th><?= $e(t('client.tracking.status')) ?></th></tr>
                <?php foreach ($activeShip as $sh): ?>
                    <tr>
                        <td><strong><?= $e($sh['waybill_no'] ?? '—') ?></strong></td>
                        <td><?= $e($courierName($sh)) ?></td>
                        <td><?= $e($shipStatus($sh)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
    <?php endif; /* box delivery/tracking */
$boxHtml['delivery'] = ob_get_clean();
$boxHtml['tracking'] = ''; // renderowane razem z 'delivery'

// Box: messages -> „Historia i wiadomości" (jak w BaseLinkerze): historia widoczna od razu,
// wiadomości dopiero po potwierdzeniu tożsamości (e-mail lub telefon z zamówienia).
$monthNames = [1 => 'stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];
$dayLabel = static function (string $ymd) use ($monthNames): string {
    $ts = strtotime($ymd . ' 12:00');
    return \Pase\Support\I18n::locale() === 'pl' ? (int) date('j', $ts) . ' ' . $monthNames[(int) date('n', $ts)] . ((int) date('Y', $ts) !== (int) date('Y') ? ' ' . date('Y', $ts) : '')
        : date('j.m.Y', $ts);
};
ob_start(); ?>
    <?php if ($box['messages']): ?>
    <div class="hist" id="wiadomosci">
        <div class="hist-head">
            <h2 class="hist-title"><?= $e(t('client.history.title')) ?></h2>
            <button type="button" class="btn-outline" onclick="var f=document.getElementById('msgNew');f.hidden=false;f.scrollIntoView({behavior:'smooth',block:'center'});var x=f.querySelector('textarea,input[name=verify_value]');if(x)x.focus();"><?= $e(t('client.history.write')) ?></button>
        </div>

        <div id="msgNew" <?= ($isVerified && empty($flashErr) && empty($verifyErr)) ? 'hidden' : '' ?>>
            <h3 class="hist-day"><?= $e(t('client.history.new_message')) ?></h3>
            <?php if (!$isVerified): ?>
                <div class="card lock-card">
                    <div class="lock-ico">✉</div>
                    <h3><?= $e(t('client.lock.title')) ?></h3>
                    <p class="muted"><?= $e(t('client.lock.intro')) ?></p>
                    <?php if (!empty($verifyErr)): ?><div class="flash err"><?= $e($verifyErr) ?></div><?php endif; ?>
                    <?php if ($blocked): ?>
                        <div class="flash err"><?= $e(t('client.verify.blocked')) ?></div>
                    <?php else: ?>
                        <button type="button" class="btn-outline" id="unlockBtn" <?= !empty($verifyErr) ? 'hidden' : '' ?>
                                onclick="this.hidden=true;var f=document.getElementById('verifyForm');f.hidden=false;f.querySelector('input[name=verify_value]').focus();">👁 <?= $e(t('client.lock.unlock')) ?></button>
                        <form method="post" id="verifyForm" class="verify-form" <?= empty($verifyErr) ? 'hidden' : '' ?>>
                            <input type="hidden" name="csrf" value="<?= $e($CSRF) ?>">
                            <input type="hidden" name="action" value="verify">
                            <input type="text" name="verify_value" placeholder="<?= $e(t('client.verify.placeholder')) ?>" required autocomplete="off">
                            <button class="btn" type="submit"><?= $e(t('client.lock.confirm')) ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="card">
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="csrf" value="<?= $e($CSRF) ?>">
                        <input type="hidden" name="action" value="client_message">
                        <input type="text" name="author_name" placeholder="<?= $e(t('client.msg.name')) ?>" value="<?= $e(trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? ''))) ?>" style="margin-bottom:8px">
                        <textarea name="message_body" rows="4" placeholder="<?= $e(t('client.msg.body')) ?>"></textarea>
                        <div style="display:flex;gap:10px;align-items:center;margin-top:10px;flex-wrap:wrap">
                            <input type="file" name="attachment" style="flex:1">
                            <button class="btn" type="submit"><?= $e(t('client.msg.send')) ?></button>
                        </div>
                        <p class="muted" style="margin-top:6px"><?= $e(t('client.msg.maxfile')) ?></p>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($lockedCount > 0): ?>
            <div class="card hist-locked">
                <span>🔒 <?= $e(t('client.history.locked', ['n' => $lockedCount])) ?></span>
                <button type="button" class="btn-outline" onclick="var f=document.getElementById('msgNew');f.hidden=false;f.scrollIntoView({behavior:'smooth',block:'center'});var b=document.getElementById('unlockBtn');if(b)b.click();"><?= $e(t('client.lock.unlock')) ?></button>
            </div>
        <?php endif; ?>

        <?php $firstDay = true; foreach ($historyByDay as $day => $entries): ?>
            <h3 class="hist-day"><?= $e($dayLabel($day)) ?><?= $firstDay ? ' - ' . $e(t('client.history.latest')) : '' ?></h3>
            <div class="card hist-card">
                <?php foreach ($entries as $h): ?>
                    <?php if ($h['type'] === 'event'): ?>
                        <div class="hist-row"><span class="hist-time"><?= $e($fmtDate($h['ts'], 'H:i')) ?></span><span><?= $e($h['text']) ?></span></div>
                    <?php elseif ($h['m']['sender'] === 'auto'): $m = $h['m']; ?>
                        <div class="hist-row hist-msg">
                            <span class="hist-time"><?= $e($fmtDate($h['ts'], 'H:i')) ?></span>
                            <div class="bubble from-staff">
                                <div class="meta"><strong><?= $e($shopName) ?></strong> · ✉ <?= $e(t('client.history.email')) ?></div>
                                <div class="mail-subject"><?= $e($m['subject']) ?></div>
                                <?php if ($m['body'] !== ''): ?>
                                    <div class="mail-body"><?= \Pase\Support\TextLinks::html($m['body']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: $m = $h['m']; $staff = $m['sender'] === 'staff'; ?>
                        <div class="hist-row hist-msg">
                            <span class="hist-time"><?= $e($fmtDate($h['ts'], 'H:i')) ?></span>
                            <div class="bubble <?= $staff ? 'from-staff' : 'from-client' ?>">
                                <div class="meta">
                                    <strong><?= $e($staff ? $shopName : ($m['author_name'] ?: t('client.messages.you'))) ?></strong>
                                    <?php if (!$staff): ?>
                                        <form method="post" style="display:inline" onsubmit="return confirm('<?= $e(t('client.msg.delete_confirm')) ?>')">
                                            <input type="hidden" name="csrf" value="<?= $e($CSRF) ?>">
                                            <input type="hidden" name="action" value="delete_message">
                                            <input type="hidden" name="message_id" value="<?= (int) $m['id'] ?>">
                                            <button type="submit" class="msg-del" title="<?= $e(t('client.msg.delete')) ?>"><?= $e(t('client.msg.delete')) ?></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                                <?= \Pase\Support\TextLinks::html((string) $m['body']) ?>
                                <?php if (!empty($m['attachment_path'])): ?>
                                    <div style="margin-top:6px"><a href="<?= $e($fileUrl) ?>token=<?= urlencode($token) ?>&id=<?= (int) $m['id'] ?>">📎 <?= $e($m['attachment_name'] ?? t('client.attachment')) ?></a></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php $firstDay = false; endforeach; ?>
        <?php if ($historyByDay === []): ?>
            <p class="muted"><?= $e(t('client.messages.empty')) ?></p>
        <?php endif; ?>
    </div>
    <?php endif; /* box messages */
$boxHtml['messages'] = ob_get_clean();

// --- Wypisz boxy w kolejności ustawionej przez administratora ---
// 'tracking' pomijamy w pętli (renderowane razem z 'delivery').
foreach ($boxOrder as $bk) {
    if ($bk === 'tracking') {
        continue;
    }
    echo $boxHtml[$bk] ?? '';
}
?>

    <!-- Przełącznik języka -->
    <p style="text-align:center;margin-top:4px">
        <?php foreach (\Pase\Support\I18n::available() as $code => $name):
            $cur = $code === \Pase\Support\I18n::locale(); ?>
            <a href="<?= $e($selfUrl) ?>?token=<?= urlencode($token) ?>&lang=<?= $e($code) ?>"
               style="font-size:12px;margin:0 5px;<?= $cur ? 'font-weight:700;color:var(--accent)' : 'color:var(--ink-2)' ?>;text-decoration:none"><?= $e($name) ?></a>
        <?php endforeach; ?>
    </p>

    <p class="muted" style="text-align:center">© <?= date('Y') ?> <?= $e($shopName) ?></p>

<?php
pageShell(t('client.order', ['order' => $orderLabel]), $shopName, (string) ob_get_clean());
