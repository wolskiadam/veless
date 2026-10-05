<?php
declare(strict_types=1);

/**
 * Asystent pakowania w panelu. Otwierany z listy zamówień (zaznaczone → „📦 Pakuj”, ?ids=1,2,3)
 * albo z zamówienia (?ids=ID). Bez ?ids pokazuje kolejkę „na telefon” zalogowanego konta.
 * Ten sam ekran działa na telefonie - patrz public/pack/.
 */

require __DIR__ . '/auth.php';

\Pase\Services\Packing::migrate($pdo);

$rawIds = $_GET['ids'] ?? [];
$ids = array_values(array_unique(array_filter(array_map('intval',
    is_array($rawIds) ? $rawIds : explode(',', (string) $rawIds)), static fn(int $i) => $i > 0)));
$ids = array_slice($ids, 0, 200);

// Powrót tam, skąd przyszliśmy (lista z filtrami / zamówienie), o ile to ten sam panel.
$back = 'index.php';
$ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
if ($ref !== '' && parse_url($ref, PHP_URL_HOST) === ($_SERVER['HTTP_HOST'] ?? '')
    && preg_match('#/admin/(index|order_view|orders)\.php#', (string) parse_url($ref, PHP_URL_PATH))) {
    $back = $ref;
}

$app = [
    'mode'     => 'panel',
    'api'      => 'packing_api.php',
    'photoUrl' => 'packing_photo.php',
    'orderUrl' => 'order_view.php?id=',
    'csrf'     => csrfToken(),
    'ids'      => $ids,
    'openId'   => (int) ($_GET['open'] ?? 0) ?: null,
    'useQueue' => $ids === [],
    'isAdmin'  => isAdmin(),
    'canEdit'  => canEdit(),
    'canLocations' => canEditPage('locations'),
    'backUrl'  => $back,
    'userName' => currentUserName(),
];
$assetBase = '../pack/assets';
$qrLib = 'assets/qrcodegen.js';
require dirname(__DIR__) . '/pack/_app.php';
