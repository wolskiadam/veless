<?php
declare(strict_types=1);

/**
 * Strona rozszerzenia (Hooks::addPage): ext.php?page=<rozszerzenie>.<id>.
 *
 * Rdzeń pilnuje logowania, roli z definicji strony (viewer / editor / admin) i tokenu CSRF
 * przy POST - rozszerzenie dostaje gotowy kontekst i oddaje treść. Strona z 'raw' => true
 * wypisuje odpowiedź sama (np. JSON dla skryptu), bez nagłówka i menu panelu.
 */

use Pase\Plugin\Hooks;

require __DIR__ . '/auth.php';
/** @var PDO $pdo */

$key  = (string) ($_GET['page'] ?? '');
$page = Hooks::page($key);
if ($page === null) {
    pageAccessDenied('Nie ma takiej strony', 'Rozszerzenie z tą stroną jest wyłączone albo usunięte.');
}

$role = (string) $page['role'];
if (($role === 'admin' && !isAdmin()) || ($role === 'editor' && !roleCanEdit())) {
    pageAccessDenied('Brak uprawnień', 'Ta strona rozszerzenia jest dostępna dla innej roli. Jeśli jest potrzebna, poproś administratora.');
}

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if ($isPost) {
    if (!roleCanEdit()) {
        pageAccessDenied('Tylko podgląd', 'Masz dostęp tylko do podglądu - zapisywanie jest zablokowane.');
    }
    csrfCheck();
}

$ctx = [
    'page'    => $key,
    'url'     => Hooks::pageUrl($key),
    'csrf'    => csrfToken(),
    'post'    => $isPost,
    'canEdit' => roleCanEdit(),
    'isAdmin' => isAdmin(),
    'userId'  => currentUserId(),
    'user'    => currentUserName(),
];

// Treść liczymy przed nagłówkiem: strona może przekierować (PRG) albo ustawić flash().
ob_start();
try {
    $ret = ($page['render'])($pdo, $ctx);
    $body = ob_get_clean() . (is_string($ret) ? $ret : '');
} catch (\Throwable $e) {
    ob_end_clean();
    \Pase\Support\Logger::warn("Rozszerzenie {$page['owner']} - błąd strony '{$key}': " . $e->getMessage());
    $body = '<div class="flash err">Strona rozszerzenia zgłosiła błąd: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

if (!empty($page['raw'])) {
    echo $body;
    return;
}

$PAGE_TITLE = (string) $page['title'];
$PAGE_KEY   = 'ext:' . $key;
require __DIR__ . '/header.php';
echo $body;
require __DIR__ . '/footer.php';
