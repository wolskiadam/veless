<?php
declare(strict_types=1);

/**
 * Połączenie konta InPost vonHalsky (OAuth2 z PKCE).
 *  - ?id=N           - start: zapamiętuje jednorazowy state i code_verifier, przekierowuje na stronę zgody InPost,
 *  - ?code=..&state= - powrót z InPost (ten adres zgłaszasz w InPost jako redirect URI integratora):
 *                      kod -> tokeny, wybór organizacji (sklepu), powrót na stronę integracji z komunikatem.
 * Szczegóły: Services\Vonhalsky.
 */

use Pase\Repository\IntegrationAccountRepository;
use Pase\Services\Vonhalsky;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
header('Cache-Control: no-store');
/** @var PDO $pdo */

$error = null;
if (!Vonhalsky::available()) {
    $error = 'Wtyczka InPost vonHalsky jest wyłączona albo usunięta. Włącz ją w Konfiguracja → Wtyczki (administrator).';
} elseif (!canEdit()) {
    $error = 'Masz tylko podgląd integracji - połączyć konto może osoba z uprawnieniem do edycji.';
} else {
    Vonhalsky::migrate($pdo);
    $svc = new Vonhalsky($pdo);

    // Powrót z InPost.
    if (isset($_GET['state'])) {
        $res = $svc->handleCallback((string) ($_GET['code'] ?? ''), (string) $_GET['state'], (string) ($_GET['error_description'] ?? $_GET['error'] ?? ''));
        $_SESSION['vonhalsky_flash'] = ['ok' => $res['ok'], 'message' => $res['message']];
        header('Location: ' . ($res['account_id'] > 0 ? 'integration_edit.php?id=' . $res['account_id'] : 'integrations.php'));
        exit;
    }

    $account = (new IntegrationAccountRepository($pdo))->find((int) ($_GET['id'] ?? 0));
    if ($account === null || ($account['type'] ?? '') !== Vonhalsky::TYPE) {
        $error = 'Nie ma takiego konta InPost vonHalsky.';
    } else {
        try {
            header('Location: ' . $svc->connectUrl($account));
            exit;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$PAGE_TITLE = 'InPost vonHalsky';
$PAGE_KEY   = 'integrations';
require __DIR__ . '/header.php';
echo '<div class="flash err">' . htmlspecialchars((string) $error) . '</div><p><a class="btn secondary" href="integrations.php">← Integracje</a></p>';
require __DIR__ . '/footer.php';
