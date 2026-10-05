<?php
declare(strict_types=1);

/**
 * Nadanie przesyłki BLPaczka dla zamówienia - dwie akcje:
 *   - action=quote : wycena (getValuation) — BEZ kosztów
 *   - action=send  : nadanie (createOrderV2) — REALNE, pobiera środki z prepaid
 *
 * Dane odbiorcy bierzemy z zamówienia (billing/shipping), nadawcy z integracji.
 * Wynik (JSON) konsumuje JS na stronie zamówienia.
 */

use Pase\Plugin\Contract\Courier;
use Pase\Plugin\PluginRegistry;
use Pase\Repository\IntegrationAccountRepository;
use Pase\Repository\ShipmentRepository;
use Pase\Repository\WooOrderRepository;
use Pase\Support\Logger;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

header('Content-Type: application/json');

/**
 * Zwraca [konto, wtyczka-Courier] pierwszej aktywnej integracji kurierskiej,
 * albo [null, null] gdy brak. Wtyczka pochodzi z rejestru (samowystarczalna).
 */
$courierPlugin = static function (PDO $pdo): array {
    $acc = (new IntegrationAccountRepository($pdo))->firstActive('blpaczka');
    if ($acc === null) {
        return [null, null];
    }
    $plugin = PluginRegistry::forAccount($acc['type'], $acc['config'] ?? []);
    return $plugin instanceof Courier ? [$acc, $plugin] : [$acc, null];
};

/**
 * Konto, środowisko, forma płatności i saldo skarbonki BLPaczka - do wyświetlenia przy
 * nadawaniu i do podpowiedzi przy błędzie „Niewystarczająca kwota na skarbonce”.
 */
$blpaczkaInfo = static function (array $cfg, object $api): array {
    $payment = \PasePlugin\Blpaczka\BlpaczkaPlugin::paymentCode($cfg);
    $bal = method_exists($api, 'balance') ? $api->balance() : ['ok' => false, 'balance' => null, 'message' => ''];
    return [
        'login'         => (string) ($cfg['login'] ?? ''),
        'env'           => ($cfg['env'] ?? 'sandbox') === 'production' ? 'production' : 'sandbox',
        'payment'       => $payment,
        'payment_label' => \PasePlugin\Blpaczka\BlpaczkaPlugin::PAYMENTS[$payment] ?? $payment,
        'balance'       => $bal['balance'] ?? null,
        'balance_error' => ($bal['ok'] ?? false) ? null : (string) ($bal['message'] ?? ''),
        'balance_raw'   => $bal['raw'] ?? null,
    ];
};

// CSRF.
$token = $_SERVER['HTTP_X_CSRF'] ?? ($_POST['csrf'] ?? '');
if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'message' => 'Nieprawidłowy token CSRF.']);
    return;
}

$action     = $_POST['action'] ?? '';
$wooOrderId = (int) ($_POST['woo_order_id'] ?? 0);

// --- Podgląd dostępnych kurierów (diagnostyka, bez kosztów) ---
if ($action === 'couriers') {
    [$bl, $courierApi] = $courierPlugin($pdo);
    if ($courierApi === null) {
        echo json_encode(['ok' => false, 'message' => 'Brak aktywnej integracji kurierskiej.']);
        return;
    }
    // Domyślna paczka do zapytania o kurierów (parametry z formularza, jeśli są).
    $parcel = [
        'weight' => (float) ($_POST['weight'] ?? 1) ?: 1,
        'side_x' => (float) ($_POST['side_x'] ?? 20) ?: 20,
        'side_y' => (float) ($_POST['side_y'] ?? 15) ?: 15,
        'side_z' => (float) ($_POST['side_z'] ?? 10) ?: 10,
    ];
    echo json_encode(['ok' => true, 'raw' => $courierApi->listServices($parcel)]);
    return;
}

// --- Podgląd dostępnych form płatności (diagnostyka) ---
if ($action === 'payments') {
    [$bl, $courierApi] = $courierPlugin($pdo);
    if ($courierApi === null) {
        echo json_encode(['ok' => false, 'message' => 'Brak aktywnej integracji kurierskiej.']);
        return;
    }
    echo json_encode(['ok' => true, 'raw' => $courierApi->listPaymentOptions()]);
    return;
}

// --- Saldo skarbonki BLPaczka (to, co widzi BLPaczka przy płatności „bank”) ---
if ($action === 'balance') {
    [$bl, $courierApi] = $courierPlugin($pdo);
    if ($courierApi === null || !method_exists($courierApi, 'balance')) {
        echo json_encode(['ok' => false, 'message' => 'Brak aktywnej integracji BLPaczka.']);
        return;
    }
    echo json_encode(['ok' => true] + $blpaczkaInfo($bl['config'] ?? [], $courierApi), JSON_UNESCAPED_UNICODE);
    return;
}

// Parametry paczki z formularza.
$weight = (float) ($_POST['weight'] ?? 0);
$x = (float) ($_POST['side_x'] ?? 0);
$y = (float) ($_POST['side_y'] ?? 0);
$z = (float) ($_POST['side_z'] ?? 0);
$courier = trim($_POST['courier_code'] ?? '');
// Sposób nadania: 'courier' (odbiór kuriera, pola pickup_*) albo 'self' (sam dostarczę -> no_pickup).
$pickupMode = ($_POST['pickup_mode'] ?? 'courier') === 'self' ? 'self' : 'courier';
$noPickup   = $pickupMode === 'self';

if ($wooOrderId <= 0 || $weight <= 0 || $x <= 0 || $y <= 0 || $z <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Uzupełnij wagę i wymiary paczki.']);
    return;
}

// Zamówienie + integracja BLPaczka (pierwsza aktywna).
$orderRow = (new WooOrderRepository($pdo))->find($wooOrderId);
if ($orderRow === null) {
    echo json_encode(['ok' => false, 'message' => 'Nie znaleziono zamówienia.']);
    return;
}
$payload = json_decode($orderRow['payload'] ?? '{}', true) ?: [];

[$bl, $courierApi] = $courierPlugin($pdo);
if ($courierApi === null) {
    echo json_encode(['ok' => false, 'message' => 'Brak aktywnej integracji kurierskiej. Dodaj ją w Integracjach.']);
    return;
}
$cfg    = $bl['config'] ?? [];
$sender = $cfg['sender'] ?? [];

// Walidacja danych nadawcy - bez kodu pocztowego nadawcy ani wycena, ani nadanie nie przejdą.
if (in_array($action, ['quote', 'send'], true) && trim($sender['postal'] ?? '') === '') {
    echo json_encode(['ok' => false, 'message' => 'Uzupełnij dane nadawcy (zwłaszcza kod pocztowy) w ustawieniach integracji BLPaczka.']);
    return;
}

// Normalizacja polskiego numeru telefonu do 9 cyfr (InPost tego wymaga).
// Usuwa wszystko poza cyframi, potem prefiks 48 / 0048 / wiodące 0.
$phonePL = static function (string $raw): string {
    $d = preg_replace('/\D+/', '', $raw);
    if (str_starts_with($d, '0048')) { $d = substr($d, 4); }
    elseif (strlen($d) === 11 && str_starts_with($d, '48')) { $d = substr($d, 2); }
    elseif (strlen($d) === 10 && str_starts_with($d, '0')) { $d = substr($d, 1); }
    return $d;
};

// Dane odbiorcy z zamówienia (billing; shipping gdy uzupełnione).
$b = $payload['billing'] ?? [];
$s = $payload['shipping'] ?? [];
if ((isset($payload['buyer']) || isset($payload['delivery'])) && empty($s['address_1']) && empty($b['address_1'])) {
    // Zamówienie z Allegro: odbiorca z delivery.address, kontakt z buyer.
    $da = $payload['delivery']['address'] ?? [];
    $by = $payload['buyer'] ?? [];
    $s = [
        'first_name' => $da['firstName'] ?? ($by['firstName'] ?? ''), 'last_name' => $da['lastName'] ?? ($by['lastName'] ?? ''),
        'address_1' => $da['street'] ?? '', 'postcode' => $da['zipCode'] ?? '', 'city' => $da['city'] ?? '',
        'phone' => $da['phoneNumber'] ?? ($by['phoneNumber'] ?? ''), 'email' => $by['email'] ?? '',
    ];
    $b = ['email' => $by['email'] ?? '', 'phone' => $by['phoneNumber'] ?? ''];
}
if (!empty($orderRow['local_billing_email'])) { $b['email'] = $orderRow['local_billing_email']; $s['email'] = $orderRow['local_billing_email']; }
if (!empty($orderRow['local_billing_phone'])) { $b['phone'] = $orderRow['local_billing_phone']; $s['phone'] = $orderRow['local_billing_phone']; }

// Punkt odbioru (paczkomat InPost, automat/punkt ORLEN Paczka...): z formularza, a gdy pusty - z zamówienia.
$pointId = trim((string) ($_POST['point_id'] ?? ''));
if ($pointId === '' && !isset($_POST['point_id'])) {
    $pointId = (string) (\Pase\Support\PickupPoint::fromOrder($payload, json_decode((string) ($orderRow['local_pickup_point'] ?? ''), true) ?: null)['id'] ?? '');
}
$pick = static fn(string $k, $def = '') => (($s[$k] ?? '') !== '' ? $s[$k] : ($b[$k] ?? $def));

$takerName = trim(($pick('first_name')) . ' ' . ($pick('last_name')));

// --- WYCENA ---
if ($action === 'quote') {
    // Pola wg CourierSearchDto, opakowane w CourierSearch (robi to serwis).
    // payment dokładamy, bo API zgłasza jego brak także przy wycenie.
    $r = $courierApi->quote([
        'courier_code'  => $courier !== '' ? $courier : null,
        'type'          => 'package',
        'weight'        => $weight,
        'side_x'        => $x,
        'side_y'        => $y,
        'side_z'        => $z,
        'origin'        => 'PL',
        'postal_sender' => $sender['postal'] ?? null,
        'payment'       => \PasePlugin\Blpaczka\BlpaczkaPlugin::paymentCode($cfg),
        'no_pickup'     => $noPickup,
    ]);
    // Log pełnej surowej odpowiedzi - do diagnozy struktury żądania (gdzie payment itd.).
    Logger::info('BLPaczka wycena RAW', ['order' => $wooOrderId, 'response' => $r['raw']]);
    echo json_encode([
        'ok' => $r['ok'],
        'price' => $r['price'],
        'message' => $r['ok'] ? ('Wycena: ' . ($r['price'] ?? '?') . ' zł') : $r['message'],
        'raw' => $r['raw'],
    ]);
    return;
}

// --- NADANIE (realne) ---
if ($action === 'send') {
    $mergedStmt = $pdo->prepare('SELECT merged_into FROM woo_orders WHERE woo_order_id = ?');
    try { $mergedStmt->execute([$wooOrderId]); $mergedInto = $mergedStmt->fetchColumn(); } catch (\PDOException) { $mergedInto = null; }
    if (!empty($mergedInto)) {
        // Scalone zamówienie jedzie w paczce głównego (Services\OrderMerge).
        echo json_encode(['ok' => false, 'message' => 'To zamówienie jest scalone z innym - nadaj przesyłkę na zamówieniu głównym albo najpierw je odłącz.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    if ($courier === '') {
        echo json_encode(['ok' => false, 'message' => 'Wybierz kuriera przed nadaniem.']);
        return;
    }

    // Struktura wg specyfikacji OpenAPI createOrderV2:
    //   CourierSearch (paczka/kurier), CartOrder (payment), Cart[ {Order: OrderDto} ].
    $courierSearch = [
        'courier_code' => $courier,
        'type'         => 'package',
        'weight'       => $weight,
        'side_x'       => $x, 'side_y' => $y, 'side_z' => $z,
        'origin'       => 'PL',
        'postal_sender'=> $sender['postal'] ?? '',
        // Bez odbioru kuriera = sam dostarczam paczkę do przewoźnika.
        'no_pickup'    => $noPickup,
    ];

    $cartOrder = ['payment' => \PasePlugin\Blpaczka\BlpaczkaPlugin::paymentCode($cfg)];

    // OrderDto - nadawca (pola bez prefiksu) + odbiorca (taker_*).
    $orderDto = [
        // Nadawca.
        'name'     => $sender['name'] ?? '',
        'email'    => $sender['email'] ?? '',
        'phone'    => $phonePL($sender['phone'] ?? ''),
        'street'   => $sender['street'] ?? '',
        'house_no' => $sender['house_no'] ?? '',
        'postal'   => $sender['postal'] ?? '',
        'city'     => $sender['city'] ?? '',
        'account'  => $sender['account'] ?? '',  // nr konta (do COD/zwrotów)
        // Odbiorca (z zamówienia).
        'taker_name'     => $takerName,
        'taker_email'    => $pick('email'),
        'taker_phone'    => $phonePL($pick('phone')),
        'taker_street'   => $pick('address_1'),
        'taker_house_no' => $pick('address_2') !== '' ? $pick('address_2') : '-',
        'taker_postal'   => $pick('postcode'),
        'taker_city'     => $pick('city'),
        // Pozostałe wymagane.
        'package_content' => 'Zamówienie ' . ($orderRow['order_number'] ?? $wooOrderId),
        'ref_number'      => (string) ($orderRow['pase_number'] ?? $wooOrderId),
    ];

    // Punkt odbioru odbiorcy („Odbiór w punkcie" w BLPaczce) - wymagany m.in. dla ORLEN Paczki i paczkomatów.
    if ($pointId !== '') {
        $orderDto['taker_point'] = $pointId;
    }

    // Pola odbioru kuriera tylko w trybie 'courier'. W trybie 'self' (no_pickup)
    // sam dostarczam paczkę do przewoźnika - terminu odbioru nie podajemy.
    if (!$noPickup) {
        $orderDto += [
            'pickup_date'              => trim($_POST['pickup_date'] ?? ''),
            'pickup_ready_time'        => (string) ((int) ($_POST['pickup_from'] ?? 9)),
            'pickup_ready_time_minute' => '00',
            'pickup_close_time'        => (string) ((int) ($_POST['pickup_to'] ?? 17)),
            'pickup_close_time_minute' => '00',
        ];
    }

    $order = [
        'CourierSearch' => $courierSearch,
        'CartOrder'     => $cartOrder,
        'Cart'          => [['Order' => $orderDto]],
    ];

    $r = $courierApi->createShipment($order);
    Logger::info('BLPaczka nadanie RAW', ['order' => $wooOrderId, 'ok' => $r['ok'], 'response' => $r['raw']]);

    if ($r['ok']) {
        (new ShipmentRepository($pdo))->create([
            'woo_order_id'     => $wooOrderId,
            'integration_id'   => (int) $bl['id'],
            'courier_code'     => $courier,
            'waybill_no'       => $r['waybill_no'],
            'bl_order_id'      => $r['external_id'] ?? null,
            'order_ref'        => $orderDto['ref_number'],
            'price'            => $r['price'],
            'status'           => 'created',
            'label_link'       => $r['label_link'],
            'request_payload'  => $order,
            'response_payload' => $r['raw'],
        ]);
        \Pase\Services\ShipmentTracking::fire($pdo, 'shipment.created', $wooOrderId,
            ['shipment_waybill' => (string) ($r['waybill_no'] ?? ''), 'shipment_carrier' => $courier]);
    }

    $hint = null;
    $info = null;
    if (!$r['ok'] && \PasePlugin\Blpaczka\BlpaczkaPlugin::isFundsError((string) $r['message'])) {
        $info = $blpaczkaInfo($cfg, $courierApi);
        $hint = \PasePlugin\Blpaczka\BlpaczkaPlugin::fundsHint((string) $r['message'], $info);
    }

    echo json_encode([
        'ok' => $r['ok'],
        'message' => $r['message'],
        'hint' => $hint,
        'account' => $info,
        'waybill_no' => $r['waybill_no'],
        'price' => $r['price'],
        'label_link' => $r['label_link'],
        'raw' => $r['raw'],
    ]);
    return;
}

echo json_encode(['ok' => false, 'message' => 'Nieznana akcja.']);
