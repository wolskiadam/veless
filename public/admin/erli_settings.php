<?php
declare(strict_types=1);

/**
 * Marketplace → Erli → Ustawienia: skrót do ustawień konta ERLI (klucz API, stany, numery przesyłek).
 * Jedno konto - od razu jego strona integracji; kilka - lista do wyboru; brak - dodawanie konta.
 * Same ustawienia i ręczna synchronizacja są na integration_edit.php (uprawnienie „Integracje”).
 */

use Pase\Services\Erli;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$accounts = array_values(array_filter(
    (new \Pase\Repository\IntegrationAccountRepository($pdo))->all(),
    static fn(array $a): bool => ($a['type'] ?? '') === Erli::TYPE
));
if (Erli::available() && count($accounts) <= 1) {
    header('Location: ' . ($accounts ? 'integration_edit.php?id=' . (int) $accounts[0]['id'] : 'integration_edit.php?type=erli'));
    exit;
}

$PAGE_TITLE = 'Erli - ustawienia';
$PAGE_KEY   = 'erli';
require __DIR__ . '/header.php';
?>
<?php if (!Erli::available()): ?>
    <div class="card"><p>Wtyczka ERLI jest wyłączona albo usunięta. Włącz ją w Konfiguracja → Wtyczki (administrator).</p></div>
<?php else: ?>
    <div class="card">
        <p style="margin:0 0 10px">Wybierz sklep ERLI:</p>
        <table><tr><th>Sklep</th><th>Stan</th><th></th></tr>
        <?php foreach ($accounts as $acc): ?>
            <tr>
                <td><strong><?= htmlspecialchars((string) $acc['name']) ?></strong></td>
                <td><?= !empty($acc['is_active']) ? '<span class="pill ok">włączone</span>' : '<span class="pill muted">wyłączone</span>' ?></td>
                <td style="text-align:right"><a class="btn secondary" href="integration_edit.php?id=<?= (int) $acc['id'] ?>">Ustawienia</a></td>
            </tr>
        <?php endforeach; ?>
        </table>
        <p style="margin:12px 0 0"><a href="integration_edit.php?type=erli">+ Dodaj kolejny sklep ERLI</a></p>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
