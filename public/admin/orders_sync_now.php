<?php
declare(strict_types=1);

/**
 * Przycisk „Pobierz teraz” na liście zamówień: pobiera nowe zamówienia ze sklepów i z Allegro od razu,
 * bez czekania na cykliczne pobieranie (Konfiguracja → Synchronizacja). Logika: Services\OrdersSyncNow.
 * POST z formularza (csrf) - wynik jako komunikat na liście zamówień.
 */

use Pase\Repository\SettingsRepository;
use Pase\Services\OrdersSyncNow;

require __DIR__ . '/auth.php';
/** @var PDO $pdo */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: index.php');
    exit;
}
csrfCheck();
if (!canEdit()) {
    http_response_code(403);
    exit('Brak uprawnień.');
}

@set_time_limit(60);
$config = require PASE_ROOT . '/config/config.php';
$r = (new OrdersSyncNow($pdo, new SettingsRepository($pdo), $config, PASE_ROOT))->run();
flash($r['message'], $r['ok'] ? 'ok' : 'err');

// Wracamy na tę samą listę (filtry, strona) - tylko adres z naszego panelu.
$back = (string) ($_POST['back'] ?? '');
redirectAfterPost(preg_match('/^index\.php(\?[^\s#]*)?$/', $back) ? $back : 'index.php');
