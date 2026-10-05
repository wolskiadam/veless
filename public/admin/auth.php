<?php
declare(strict_types=1);

/**
 * Autoryzacja panelu PASE - wzorzec przeniesiony z sds-generator
 * (sesja PHP + tabela users + role admin/editor/viewer).
 *
 * Każda strona panelu zaczyna od:  require __DIR__ . '/auth.php';
 * co wymusza zalogowanie. Funkcje pomocnicze (currentUser*, isAdmin,
 * requireRole) są identyczne jak w systemie, który już znasz.
 */

// Sesja panelu: długa i przesuwana przy aktywności - patrz Pase\Support\Session.
// Kolejność ma znaczenie: db_admin.php ładuje config.php (a z nim autoloader),
// więc najpierw wczytujemy bootstrap, potem startujemy sesję.
require_once __DIR__ . '/db_admin.php';

\Pase\Support\Session::startAdmin();
\Pase\Support\SecurityHeaders::forPanel();
/** @var PDO $pdo */

// Tabela admin_users + domyślne konto (wspólne z login.php).
require_once __DIR__ . '/bootstrap_admin.php';

// ============================================================
//  Wylogowanie
// ============================================================
if (isset($_GET['logout'])) {
    if (isset($_SESSION['pase_user_id'], $_SESSION['pase_device_id'])) {
        (new \Pase\Support\AdminDevices($pdo))->revoke((int) $_SESSION['pase_user_id'], (int) $_SESSION['pase_device_id']);
    }
    session_destroy();
    header('Location: login.php');
    exit;
}

// ============================================================
//  Strażnik - brak sesji => login
//  (login.php sam NIE includuje tego pliku, by uniknąć pętli)
// ============================================================
if (!isset($_SESSION['pase_user_id'])) {
    header('Location: login.php');
    exit;
}

$accountQuery = $pdo->prepare('SELECT * FROM admin_users WHERE id = ?');
$accountQuery->execute([(int) $_SESSION['pase_user_id']]);
$account = $accountQuery->fetch(PDO::FETCH_ASSOC);
// Urządzenie wylogowane z listy w Bezpieczeństwo konta → 2FA traci dostęp przy następnym żądaniu.
if (!\Pase\Support\AdminSession::valid($account, $_SESSION)
    || !(new \Pase\Support\AdminDevices($pdo))->check($account)) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}
$_SESSION['pase_user_role'] = $account['role'];
$_SESSION['pase_username'] = $account['username'];
$_SESSION['pase_display_name'] = $account['display_name'] ?: $account['username'];

// Uprawnienia do stron (jak w sds-generator). null = konto działa wg roli (brak zapisanych uprawnień).
$GLOBALS['pase_page_perms'] = $account['role'] === 'admin'
    ? null : \Pase\Support\PagePermissions::load($pdo, (int) $account['id']);

// Any account subject to enrollment policy can only access 2FA settings.
if (\Pase\Services\TwoFactorService::required($account) && empty($account['totp_secret'])
    && basename($_SERVER['SCRIPT_FILENAME'] ?? '') !== 'security.php') {
    header('Location: security.php');
    exit;
}

// ============================================================
//  Język panelu (per użytkownik, w sesji). Przełącznik: ?setlang=xx
// ============================================================
if (isset($_GET['setlang']) && \Pase\Support\I18n::isAvailable((string) $_GET['setlang'])) {
    $_SESSION['pase_lang'] = preg_replace('/[^a-z]/', '', strtolower((string) $_GET['setlang']));
    // PRG: wróć na tę samą stronę bez parametru setlang.
    $url = strtok($_SERVER['REQUEST_URI'] ?? 'index.php', '?');
    $qs  = $_GET; unset($qs['setlang']);
    header('Location: ' . $url . ($qs ? '?' . http_build_query($qs) : ''));
    exit;
}
\Pase\Support\I18n::setLocale($_SESSION['pase_lang'] ?? \Pase\Support\I18n::DEFAULT);

// ============================================================
//  Funkcje pomocnicze (zgodne nazewnictwo z sds-generator)
// ============================================================
function currentUserId(): ?int
{
    return $_SESSION['pase_user_id'] ?? null;
}

function currentUserRole(): string
{
    return $_SESSION['pase_user_role'] ?? 'viewer';
}

function currentUserName(): string
{
    return $_SESSION['pase_display_name'] ?? $_SESSION['pase_username'] ?? '';
}

function isAdmin(): bool
{
    return currentUserRole() === 'admin';
}

/** Uprawnienie do edycji wynikające z samej roli (stary model, bez uprawnień do stron). */
function roleCanEdit(): bool
{
    return in_array(currentUserRole(), ['admin', 'editor'], true);
}

/**
 * Czy można zapisywać na BIEŻĄCEJ stronie. Konto ze szczegółowymi uprawnieniami:
 * poziom „Edycja” tej strony; bez nich (albo strona spoza rejestru) - wg roli.
 */
function canEdit(): bool
{
    if (isAdmin()) {
        return true;
    }
    if (usesPagePermissions() && ($key = currentPageKey()) !== null) {
        return getUserPageAccess($key) === 'edit';
    }
    return roleCanEdit();
}

/** Wymaga jednej z ról; 403 jeśli brak. */
function requireRole(string|array $roles): void
{
    $roles = (array) $roles;
    // Szczegółowe uprawnienia: dostęp nadany do tej strony zastępuje wymóg roli
    // (zapis i tak blokuje strażnik podglądu w requirePageAccess()).
    if (!in_array(currentUserRole(), $roles, true) && usesPagePermissions()
        && ($key = currentPageKey()) !== null && canViewPage($key)) {
        return;
    }
    if (!in_array(currentUserRole(), $roles, true)) {
        http_response_code(403);
        echo '<div style="max-width:480px;margin:80px auto;font-family:system-ui;text-align:center">';
        echo '<h2 style="color:#c5221f">' . htmlspecialchars(t('common.noperm.title')) . '</h2>';
        echo '<p>' . htmlspecialchars(t('common.noperm.role', ['roles' => implode(' / ', $roles)])) . '</p>';
        echo '<a href="index.php">' . htmlspecialchars(t('common.back')) . '</a></div>';
        exit;
    }
}

/** Ochrona CSRF - token w sesji. */
function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

/** Token CSRF z żądania: pole formularza, nagłówek X-CSRF / X-CSRF-Token albo pole csrf w treści JSON. */
function csrfSentToken(): string
{
    $sent = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if ($sent === null && str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'json')) {
        $json = json_decode((string) file_get_contents('php://input', false, null, 0, 1048576), true);
        $sent = is_array($json) ? ($json['csrf'] ?? null) : null;
    }
    return is_string($sent) ? $sent : '';
}

function csrfCheck(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || $sent === '' || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        http_response_code(419);
        exit('Nieprawidłowy token CSRF - odśwież stronę i spróbuj ponownie.');
    }
}

/** Every physical print action requires explicit, authenticated POST. */
function requirePrintPost(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        http_response_code(405);
        exit('Wysłanie do drukarki wymaga POST.');
    }
    requireRole(['admin', 'editor']);
    csrfCheck();
}

// ============================================================
//  Komunikaty (flash) + wzorzec POST/Redirect/GET
//
//  Problem: strona, która po POST renderuje się wprost z tego żądania,
//  zostawia przeglądarkę "na POST-cie". Każde odświeżenie (F5, powrót do
//  zakładki) pyta wtedy "czy przesłać dane ponownie" i potrafi powtórzyć
//  zapis. Lekarstwem jest przekierowanie 303 zaraz po zapisie - użytkownik
//  ląduje na zwykłym GET-cie, który można odświeżać bez konsekwencji.
//
//  Użycie na stronie:
//      csrfCheck();
//      ... zapis ...
//      flash('Zapisano ustawienia.');   // drugi argument: 'ok' (domyślnie) / 'err'
//      redirectAfterPost();             // 303 na ten sam adres
//
//  Komunikat wypisuje header.php, więc strona nie musi już trzymać
//  zmiennej typu $flashOk ani renderować <div class="flash"> samodzielnie.
// ============================================================

/** Odkłada komunikat w sesji, żeby przetrwał przekierowanie. */
function flash(string $message, string $type = 'ok'): void
{
    $_SESSION['pase_flash'][] = [
        'type' => $type === 'err' ? 'err' : 'ok',
        'msg'  => $message,
    ];
}

/** Zwraca odłożone komunikaty i od razu je czyści (wywoływane przez header.php). */
function flashTake(): array
{
    $messages = $_SESSION['pase_flash'] ?? [];
    unset($_SESSION['pase_flash']);
    return is_array($messages) ? $messages : [];
}

/**
 * POST/Redirect/GET - kończy obsługę POST-a przekierowaniem na GET.
 *
 * Bez argumentu wraca na tę samą stronę zachowując query string (np. ?id=12),
 * więc kontekst edytowanego rekordu nie ginie. Kod 303 jest tu właściwy:
 * mówi przeglądarce wprost "pobierz to GET-em", niezależnie od metody
 * pierwotnego żądania (301/302 bywają w tej sytuacji interpretowane różnie).
 */
function redirectAfterPost(?string $to = null): void
{
    if ($to === null) {
        $to = basename((string) ($_SERVER['PHP_SELF'] ?? 'index.php'));
        $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
        if ($qs !== '') {
            $to .= '?' . $qs;
        }
    }
    header('Location: ' . $to, true, 303);
    exit;
}

// ============================================================
//  Uprawnienia do stron (Ukryte / Podgląd / Edycja) - model z sds-generator.
//  Rejestr stron i reguły: Pase\Support\PagePermissions.
// ============================================================

/** Czy konto ma zapisane szczegółowe uprawnienia (admin nigdy). */
function usesPagePermissions(): bool
{
    return !isAdmin() && is_array($GLOBALS['pase_page_perms'] ?? null);
}

/** Klucz bieżącej strony z rejestru albo null. */
function currentPageKey(): ?string
{
    return \Pase\Support\PagePermissions::keyForFile((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
}

/** Poziom dostępu: 'hidden' | 'view' | 'edit'. */
function getUserPageAccess(string $pageKey): string
{
    return \Pase\Support\PagePermissions::access(currentUserRole(), $GLOBALS['pase_page_perms'] ?? null, $pageKey);
}

function canViewPage(string $pageKey): bool
{
    return getUserPageAccess($pageKey) !== 'hidden';
}

function canEditPage(string $pageKey): bool
{
    return getUserPageAccess($pageKey) === 'edit';
}

/** Czy bieżąca strona jest otwarta tylko do podglądu. */
function pageViewOnly(): bool
{
    return usesPagePermissions() && ($key = currentPageKey()) !== null && getUserPageAccess($key) === 'view';
}

/** Zgodność z sds-generator: czy na bieżącej stronie można edytować. */
function pageCanEdit(): bool
{
    return !pageViewOnly();
}

/** Widoczność pozycji menu (plik strony) dla bieżącego konta. */
function canOpenPage(string $file): bool
{
    if (!usesPagePermissions()) {
        return true;
    }
    $key = \Pase\Support\PagePermissions::keyForFile($file);
    return $key === null || canViewPage($key);
}

/** Strona błędu uprawnień (403) w wyglądzie panelu. */
function pageAccessDenied(string $title, string $message, string $back = 'index.php'): never
{
    http_response_code(403);
    if (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo '<!DOCTYPE html><html lang="pl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . htmlspecialchars($title) . '</title></head>';
    echo '<body style="margin:0;background:#f6f5f2;font-family:system-ui,sans-serif;color:#22252b">';
    echo '<div style="max-width:480px;margin:80px auto;padding:32px;background:#fff;border:1px solid #e6e3da;border-radius:10px;text-align:center">';
    echo '<h2 style="color:#a3341f;margin:0 0 12px">' . htmlspecialchars($title) . '</h2>';
    echo '<p>' . htmlspecialchars($message) . '</p>';
    echo '<a href="' . htmlspecialchars($back) . '" style="display:inline-block;margin-top:12px;padding:9px 16px;background:#9c6b2e;color:#fff;border-radius:8px;text-decoration:none;font-weight:700">Wróć</a>';
    echo '</div></body></html>';
    exit;
}

/**
 * Wymaga dostępu do strony. W trybie podglądu blokuje zapis (POST/PUT/DELETE/PATCH)
 * i żądania GET, które coś zmieniają (np. uruchomienie importu).
 */
function requirePageAccess(string $pageKey, string $minLevel = 'view'): void
{
    $access = getUserPageAccess($pageKey);
    if ($access === 'hidden' || ($minLevel === 'edit' && $access !== 'edit')) {
        pageAccessDenied('Brak uprawnień', 'Nie masz dostępu do tej strony. Jeśli jest potrzebny, poproś administratora.');
    }
    if ($access === 'view') {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)
            || \Pase\Support\PagePermissions::isGetWrite((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''), $_GET)) {
            pageAccessDenied('Tylko podgląd', 'Masz dostęp tylko do podglądu tej strony - zapisywanie i zmiany są zablokowane.',
                basename((string) ($_SERVER['PHP_SELF'] ?? 'index.php')));
        }
    }
}

// Strażnik: każda strona z rejestru jest sprawdzana automatycznie, zanim wykona cokolwiek.
if (usesPagePermissions() && ($__pageKey = currentPageKey()) !== null) {
    // Strona startowa (lista zamówień) ukryta - przejdź na pierwszą dostępną stronę zamiast błędu.
    if ($__pageKey === 'orders' && basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'index.php' && !canViewPage('orders')) {
        foreach (\Pase\Support\PagePermissions::registry() as $__k => $__page) {
            if (canViewPage($__k)) {
                header('Location: ' . $__page['files'][0]);
                exit;
            }
        }
        header('Location: security.php'); // brak jakichkolwiek stron - zostaje konto (2FA) i wylogowanie
        exit;
    }
    requirePageAccess($__pageKey);
}
unset($__pageKey, $__k, $__page);

// Znacznik „ktoś pracuje w panelu” - agent drukarki pyta wtedy częściej (Pase\Services\PrintAgentPacing).
if (defined('PASE_ROOT') && class_exists(\Pase\Services\PrintAgentPacing::class)) {
    \Pase\Services\PrintAgentPacing::touchPanelActivity(PASE_ROOT, (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
}

// Praca przeniesiona na drugą instalację (online ⇄ komputer, Pase\Services\Handover): tu tylko podgląd.
// Zmiany zapisane teraz i tak zniknęłyby przy powrocie danych, więc ich nie przyjmujemy.
$GLOBALS['pase_handover_away'] = null;
try { $GLOBALS['pase_handover_away'] = (new \Pase\Services\Handover($pdo, new \Pase\Repository\SettingsRepository($pdo), PASE_ROOT))->away(); } catch (\Throwable) {}
if ($GLOBALS['pase_handover_away'] !== null && strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST'
    && !in_array(basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')), ['handover.php', 'security.php'], true)) {
    flash('Praca jest przeniesiona na ' . ($GLOBALS['pase_handover_away']['to'] === 'local' ? 'komputer' : 'online') . ' - tutaj nie można nic zmieniać.', 'err');
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
    exit;
}

// Każdy POST w panelu wymaga tokenu CSRF - także strona, która zapomni wywołać csrfCheck().
// (Strony nadal mogą sprawdzać same; podwójne sprawdzenie niczego nie psuje.)
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    $__csrf = csrfSentToken();
    if ($__csrf === '' || empty($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], $__csrf)) {
        http_response_code(419);
        exit('Nieprawidłowy token CSRF - odśwież stronę i spróbuj ponownie.');
    }
    unset($__csrf);
}
