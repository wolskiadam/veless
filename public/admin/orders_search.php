<?php
declare(strict_types=1);

/**
 * Wyszukiwanie na żywo (AJAX) dla listy zamówień - zwraca JSON z gotową
 * kartą "Lista" (ta sama co pełne przeładowanie index.php) + licznikiem
 * "Znaleziono". Filtrowanie/paginacja to DOKŁADNIE ta sama logika co
 * index.php, patrz _orders_query.php (żeby wyniki się nie rozjeżdżały).
 *
 * GET: te same parametry co index.php (q, status, date_from, date_to,
 * total_min, total_max, unread, need_invoice, need_shipping (1 lub etap śledzenia), unpaid, need_receipt, view, p).
 */

use Pase\Repository\OrderStatusRepository;

require __DIR__ . '/auth.php';
/** @var PDO $pdo */

$statusRepo = new OrderStatusRepository($pdo);
$statusList = $statusRepo->all();
$statusMap  = [];
foreach ($statusList as $s) {
    $statusMap[$s['status_key']] = $s;
}

$view = in_array($_GET['view'] ?? '', ['archived', 'trashed'], true) ? $_GET['view'] : 'active';

require __DIR__ . '/_orders_query.php';

ob_start();
require __DIR__ . '/_orders_table.php';
$html = ob_get_clean();

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'html'  => $html,
    'found' => t('common.found', ['n' => $totalRows]),
], JSON_UNESCAPED_UNICODE);
