<?php
declare(strict_types=1);

/**
 * Nadanie paczki przez WTYCZKĘ KURIERSKĄ wybraną na stronie zamówienia (kontrakt Courier).
 * Dotyczy wtyczek innych niż BLPaczka (ta ma własny formularz: shipment_create.php).
 *
 * POST: csrf, integration=<id konta>, woo_order_id, action:
 *   services : lista umów/usług do wyboru (listServices) + podpowiedź paczki (proposal, jeśli wtyczka ją ma)
 *   send     : nadanie (createShipment) - REALNE
 *   manual   : „Inne" - zapis numeru przesyłki nadanej poza systemem (bez wtyczki)
 *   quote    : wycena przed nadaniem - Courier::quote(); gdy wtyczka nie zna ceny,
 *              ostatni faktyczny koszt tą samą usługą i sposobem nadania z historii przesyłek
 *   points   : wyszukiwanie punktu odbioru (q=…) - tylko wtyczki z metodą searchPoints() (np. ORLEN Paczka)
 * Zwraca JSON dla JS na stronie zamówienia.
 */

use Pase\Repository\ShipmentRepository;
use Pase\Repository\WooOrderRepository;
use Pase\Services\CourierShipping;
use Pase\Support\Logger;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

header('Content-Type: application/json');

$token = $_SERVER['HTTP_X_CSRF'] ?? ($_POST['csrf'] ?? '');
if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'message' => 'Nieprawidłowy token CSRF.']);
    return;
}

$action        = $_POST['action'] ?? '';
$wooOrderId    = (int) ($_POST['woo_order_id'] ?? 0);
$integrationId = (int) ($_POST['integration'] ?? 0);

$row = (new WooOrderRepository($pdo))->find($wooOrderId);
if ($row === null) {
    echo json_encode(['ok' => false, 'message' => 'Nie znaleziono zamówienia.']);
    return;
}
$order = ['row' => $row, 'payload' => json_decode((string) ($row['payload'] ?? ''), true) ?: []];
if (!empty($row['merged_into']) && in_array($action, ['manual', 'send'], true)) {
    // Scalone zamówienie jedzie w paczce głównego (Services\OrderMerge) - osobna paczka to podwójna wysyłka.
    echo json_encode(['ok' => false, 'message' => 'To zamówienie jest scalone z innym - nadaj przesyłkę na zamówieniu głównym albo najpierw je odłącz.'], JSON_UNESCAPED_UNICODE);
    return;
}

// „Inne": przesyłka nadana poza systemem - tylko zapisujemy numer przy zamówieniu.
if ($action === 'manual') {
    $carrier = mb_substr(trim((string) ($_POST['carrier'] ?? '')), 0, 40);
    $waybill = mb_substr(trim((string) ($_POST['waybill'] ?? '')), 0, 100);
    if ($carrier === '' || $waybill === '') {
        echo json_encode(['ok' => false, 'message' => 'Podaj przewoźnika i numer przesyłki.']);
        return;
    }
    $shipmentId = (new ShipmentRepository($pdo))->create([
        'woo_order_id' => $wooOrderId,
        'courier_code' => $carrier,
        'waybill_no'   => $waybill,
        'order_ref'    => (string) ($row['pase_number'] ?? $wooOrderId),
        'status'       => 'created',
    ]);
    \Pase\Services\ShipmentTracking::fire($pdo, 'shipment.created', $wooOrderId,
        ['shipment_waybill' => $waybill, 'shipment_carrier' => $carrier]);
    // Od razu pytamy przewoźnika o etap (śledzenie Allegro), zamiast czekać na harmonogram.
    $msg = 'Zapisano przesyłkę.';
    try {
        $cfg = \Pase\Support\Runtime::config('allegro');
        $trk = (new \Pase\Services\ShipmentTracking($pdo, \PasePlugin\Allegro\AllegroPlugin::makeClient($pdo, $cfg), $cfg))
            ->checkOne((int) $shipmentId);
        $msg .= ' ' . $trk['message'];
    } catch (\Throwable $e) {
        Logger::warn('Śledzenie ręcznej przesyłki nie powiodło się: ' . $e->getMessage(), ['order' => $wooOrderId]);
    }
    echo json_encode(['ok' => true, 'message' => $msg]);
    return;
}

$courier = new CourierShipping($pdo);
$plugin  = $courier->pluginForAccount($integrationId);
if ($plugin === null) {
    echo json_encode(['ok' => false, 'message' => 'Wybrana integracja kurierska jest niedostępna lub wyłączona.']);
    return;
}
if (method_exists($plugin, 'supportsOrder') && !$plugin->supportsOrder($order)) {
    echo json_encode(['ok' => false, 'message' => 'Ta integracja nie obsługuje tego zamówienia.']);
    return;
}
if (!CourierShipping::servesShop($courier->account($integrationId) ?? [], $order)) {
    echo json_encode(['ok' => false, 'message' => 'To konto kurierskie nie jest ustawione dla sklepu tego zamówienia (Integracje → „Używaj dla zamówień ze sklepów”).']);
    return;
}

try {
    if ($action === 'services') {
        $services = $plugin->listServices(['order' => $order]);
        $proposal = method_exists($plugin, 'proposal') ? $plugin->proposal($order) : null;
        echo json_encode(['ok' => true, 'services' => $services, 'proposal' => $proposal]);
        return;
    }

    if ($action === 'points') {
        if (!method_exists($plugin, 'searchPoints')) {
            echo json_encode(['ok' => false, 'points' => [], 'message' => 'Ta integracja nie ma wyszukiwarki punktów.']);
            return;
        }
        $r = $plugin->searchPoints(mb_substr(trim((string) ($_POST['q'] ?? '')), 0, 60));
        echo json_encode(['ok' => $r['ok'], 'points' => $r['points'], 'message' => $r['message']], JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($action === 'quote') {
        $params = [
            'order'         => $order,
            'parcel'        => ['weight' => (float) ($_POST['weight'] ?? 0), 'x' => (float) ($_POST['side_x'] ?? 0),
                                'y' => (float) ($_POST['side_y'] ?? 0), 'z' => (float) ($_POST['side_z'] ?? 0)],
            'service'       => (string) ($_POST['service'] ?? ''),
            'service_label' => (string) ($_POST['service_label'] ?? ''),
            'handover'      => (string) ($_POST['handover'] ?? ''),
            'point'         => trim((string) ($_POST['point'] ?? '')),
        ];
        $q = $plugin->quote($params);
        if (!empty($q['ok']) && $q['price'] !== null) {
            echo json_encode(['ok' => true, 'price' => (float) $q['price'], 'source' => $q['message'] ?: 'wycena przewoźnika']);
            return;
        }
        // Historia: ostatni faktyczny koszt tą samą usługą i sposobem nadania.
        $hist = $pdo->prepare("SELECT price, request_payload, created_at FROM shipments
                               WHERE integration_id = ? AND price IS NOT NULL AND status = 'created'
                               ORDER BY id DESC LIMIT 100");
        $hist->execute([$integrationId]);
        foreach ($hist->fetchAll(PDO::FETCH_ASSOC) as $h) {
            $req = json_decode((string) ($h['request_payload'] ?? ''), true) ?: [];
            if (($req['service_label'] ?? null) === $params['service_label'] && ($req['handover'] ?? '') === $params['handover']) {
                echo json_encode(['ok' => true, 'price' => (float) $h['price'],
                    'source' => 'ostatni faktyczny koszt tą metodą (' . substr((string) $h['created_at'], 0, 10) . ')']);
                return;
            }
        }
        echo json_encode(['ok' => false, 'price' => null,
            'message' => 'Brak wyceny — uzupełnij cennik w ustawieniach integracji; faktyczny koszt pokaże się po nadaniu.']);
        return;
    }

    if ($action === 'send') {
        $parcel = [
            'weight' => (float) ($_POST['weight'] ?? 0),
            'x'      => (float) ($_POST['side_x'] ?? 0),
            'y'      => (float) ($_POST['side_y'] ?? 0),
            'z'      => (float) ($_POST['side_z'] ?? 0),
        ];
        if (min($parcel) <= 0) {
            echo json_encode(['ok' => false, 'message' => 'Uzupełnij wagę i wymiary paczki.']);
            return;
        }
        $shipment = [
            'order'         => $order,
            'parcel'        => $parcel,
            'service'       => (string) ($_POST['service'] ?? ''),
            'label_format'  => (string) ($_POST['label_format'] ?? ''),
            'text_on_label' => trim((string) ($_POST['text_on_label'] ?? '')),
            'handover'      => (string) ($_POST['handover'] ?? ''),
            'pickup_date'   => (string) ($_POST['pickup_date'] ?? ''),
            'point'         => mb_substr(trim((string) ($_POST['point'] ?? '')), 0, 40),
        ];
        $r = $plugin->createShipment($shipment);
        Logger::info('Kurier nadanie', ['order' => $wooOrderId, 'integration' => $integrationId, 'ok' => $r['ok'], 'pending' => $r['pending'] ?? false]);

        if ($r['ok']) {
            (new ShipmentRepository($pdo))->create([
                'woo_order_id'     => $wooOrderId,
                'integration_id'   => $integrationId,
                'courier_code'     => $r['courier_label'] ?? $plugin->manifest()->name,
                'waybill_no'       => $r['waybill_no'] ?? null,
                'bl_order_id'      => $r['external_id'] ?? null,
                'order_ref'        => (string) ($row['pase_number'] ?? $wooOrderId),
                'price'            => $r['price'] ?? null,
                'status'           => !empty($r['pending']) ? 'pending' : 'created',
                'label_link'       => $r['label_link'] ?? null,
                'request_payload'  => $shipment['parcel'] + [
                    'service'       => $shipment['service'],
                    'service_label' => (string) ($_POST['service_label'] ?? ''),
                    'handover'      => $shipment['handover'],
                    'pickup_date'   => $shipment['pickup_date'],
                    'point'         => $shipment['point'],
                    'label_format'  => $shipment['label_format'],
                    'quoted_price'  => isset($_POST['quoted_price']) && $_POST['quoted_price'] !== '' ? (float) $_POST['quoted_price'] : null,
                ],
                'response_payload' => $r['raw'] ?? null,
            ]);
            if (empty($r['pending'])) {
                \Pase\Services\ShipmentTracking::fire($pdo, 'shipment.created', $wooOrderId,
                    ['shipment_waybill' => (string) ($r['waybill_no'] ?? ''), 'shipment_carrier' => (string) ($r['courier_label'] ?? '')]);
            }
        }
        echo json_encode(['ok' => $r['ok'], 'pending' => $r['pending'] ?? false, 'message' => $r['message'], 'waybill_no' => $r['waybill_no'] ?? null]);
        return;
    }
} catch (\Throwable $e) {
    Logger::error('Kurier: błąd wtyczki', ['integration' => $integrationId, 'error' => $e->getMessage()]);
    echo json_encode(['ok' => false, 'message' => 'Błąd wtyczki kurierskiej: ' . $e->getMessage()]);
    return;
}

echo json_encode(['ok' => false, 'message' => 'Nieznana akcja.']);
