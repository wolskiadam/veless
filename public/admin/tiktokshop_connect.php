<?php
declare(strict_types=1);

/**
 * Połączenie konta TikTok Shop (OAuth).
 *  - ?id=N           - start: zapamiętuje jednorazowy state i przekierowuje na stronę zgody TikTok,
 *  - ?code=..&state= - powrót z TikTok (ten adres wpisujesz w Partner Center jako Redirect URL aplikacji):
 *                      kod -> token, wybór sklepu, powrót na stronę integracji z komunikatem.
 * Szczegóły: Services\TiktokShop.
 */

use Pase\Repository\IntegrationAccountRepository;
use Pase\Services\TiktokShop;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
header('Cache-Control: no-store');
/** @var PDO $pdo */

$error = null;
if (!TiktokShop::available()) {
    $error = 'Wtyczka TikTok Shop jest wyłączona albo usunięta. Włącz ją w Konfiguracja → Wtyczki (administrator).';
} elseif (!canEdit()) {
    $error = 'Masz tylko podgląd integracji - połączyć konto może osoba z uprawnieniem do edycji.';
} else {
    TiktokShop::migrate($pdo);
    $svc = new TiktokShop($pdo);

    // Powrót z TikTok.
    if (isset($_GET['state'])) {
        $res = $svc->handleCallback((string) ($_GET['code'] ?? ''), (string) $_GET['state']);
        $_SESSION['tiktokshop_flash'] = ['ok' => $res['ok'], 'message' => $res['message']];
        header('Location: ' . ($res['account_id'] > 0 ? 'integration_edit.php?id=' . $res['account_id'] : 'integrations.php'));
        exit;
    }

    $account = (new IntegrationAccountRepository($pdo))->find((int) ($_GET['id'] ?? 0));
    if ($account === null || ($account['type'] ?? '') !== TiktokShop::TYPE) {
        $error = 'Nie ma takiego konta TikTok Shop.';
    } else {
        try {
            header('Location: ' . $svc->connectUrl($account));
            exit;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$PAGE_TITLE = 'TikTok Shop';
$PAGE_KEY   = 'integrations';
require __DIR__ . '/header.php';
echo '<div class="flash err">' . htmlspecialchars((string) $error) . '</div><p><a class="btn secondary" href="integrations.php">← Integracje</a></p>';
require __DIR__ . '/footer.php';
