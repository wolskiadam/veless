<?php
declare(strict_types=1);

/**
 * Logika filtrowania/paginacji listy zamówień - WSPÓLNA dla pełnego przeładowania
 * (index.php) i wyszukiwania na żywo (orders_search.php), żeby nie duplikować
 * (i nie rozjeżdżać) reguł filtrowania w dwóch miejscach.
 *
 * Wymaga wcześniej: $pdo, $view ('active'|'archived'|'trashed').
 * Plik include-only (patrz .htaccess) - bez własnego auth/sesji.
 */

// Liczba zamówień na stronę: wybór pod listą (?per_page=N), zapamiętany na stałe dla użytkownika
// (settings: ORDERS_PER_PAGE_<id>), więc po wylogowaniu i na innym komputerze zostaje ten sam.
$perPageOptions = [25, 50, 75, 100, 150, 200];
$perPageKey = 'ORDERS_PER_PAGE_' . (int) (function_exists('currentUserId') ? currentUserId() : 0);
$perPageRepo = new \Pase\Repository\SettingsRepository($pdo);
$perPage = (int) ($perPageRepo->get($perPageKey, '25') ?? '25');
if (isset($_GET['per_page']) && in_array((int) $_GET['per_page'], $perPageOptions, true)) {
    if ((int) $_GET['per_page'] !== $perPage) {
        try { $perPageRepo->setMany([$perPageKey => (string) (int) $_GET['per_page']]); } catch (\Throwable) {}
    }
    $perPage = (int) $_GET['per_page'];
}
if (!in_array($perPage, $perPageOptions, true)) {
    $perPage = 25;
}

// --- Parametry filtrów (z GET) ---
$q          = trim($_GET['q'] ?? '');
$fStatus    = trim($_GET['status'] ?? '');   // status PASE (pase_status)
$fDateFrom  = trim($_GET['date_from'] ?? '');
$fDateTo    = trim($_GET['date_to'] ?? '');
$fTotalMin  = trim($_GET['total_min'] ?? '');
$fTotalMax  = trim($_GET['total_max'] ?? '');
$fUnread    = ($_GET['unread'] ?? '') === '1'; // tylko z nowymi wiadomościami klienta
$fNeedInvoice  = ($_GET['need_invoice'] ?? '') === '1';  // wskaźnik "faktura" = warn (wymagana)
// Filtr „Wysyłka": '1' = wymaga wysyłki (wskaźnik "wysyłka" = warn) albo etap śledzenia przesyłki
// (klucz z OrderIndicators::TRACKING_FILTERS, np. in_transit, delivered).
$fShipping = (string) ($_GET['need_shipping'] ?? '');
if ($fShipping !== '1' && !isset(\Pase\Services\OrderIndicators::TRACKING_FILTERS[$fShipping])) { $fShipping = ''; }
$fNeedShipping = $fShipping === '1';
$fShipmentTracking = $fShipping !== '' && !$fNeedShipping ? $fShipping : '';
$fUnpaid       = ($_GET['unpaid'] ?? '') === '1';        // wskaźnik "płatność" = warn (nieopłacone/pobranie)
$fNeedReceipt  = ($_GET['need_receipt'] ?? '') === '1';  // wskaźnik "paragon" = warn (bez faktury i bez paragonu)
$page       = max(1, (int) ($_GET['p'] ?? 1));
$fSource    = trim($_GET['source'] ?? '');   // źródło zamówienia - patrz budowa WHERE niżej
$fInvoiceIssued = in_array($_GET['invoice_issued'] ?? '', ['1', '0'], true) ? $_GET['invoice_issued'] : '';
$fReceiptIssued = in_array($_GET['receipt_issued'] ?? '', ['1', '0'], true) ? $_GET['receipt_issued'] : '';
$invoiceExists = "EXISTS (SELECT 1 FROM order_documents od WHERE od.woo_order_id = wo.woo_order_id AND od.status = 'issued' AND od.document_type = 'normal')";
$receiptExists = "EXISTS (SELECT 1 FROM order_documents od WHERE od.woo_order_id = wo.woo_order_id AND od.status = 'issued' AND od.document_type IN ('receipt', 'receipt_fiscal'))";
// ID paragonu w wFirma - zielona ikona paragonu na liście otwiera go w wFirma.
$receiptRemote = "(SELECT od.remote_id FROM order_documents od WHERE od.woo_order_id = wo.woo_order_id AND od.status = 'issued' AND od.provider = 'wfirma' AND od.document_type IN ('receipt', 'receipt_fiscal') ORDER BY od.id DESC LIMIT 1)";
$invoiceRemote = "(SELECT od.remote_id FROM order_documents od WHERE od.woo_order_id = wo.woo_order_id AND od.status = 'issued' AND od.provider = 'wfirma' AND od.document_type = 'normal' ORDER BY od.id DESC LIMIT 1)";

// Mapa zamówień z nieprzeczytanymi wiadomościami klienta (do podświetlania + filtra).
$unreadByOrder = [];
try {
    $unreadByOrder = (new \Pase\Repository\OrderMessageRepository($pdo))->unreadByOrder();
} catch (\Throwable $e) {
    $unreadByOrder = [];
}

// --- Budowa WHERE dynamicznie ---
$where  = [];
$params = [];

// Widok cyklu życia (aktywne / archiwum / kosz) - zawsze ogranicza listę i liczniki.
$where[] = 'wo.lifecycle = :life';
$params[':life'] = $view;
// Zamówienia dołączone do innego (scalanie, Services\OrderMerge) nie mają własnego wiersza -
// na liście jest jedno zamówienie główne z łączną ceną.
$hasMergeColumn = \Pase\Services\LowStock::columnExists($pdo, 'woo_orders', 'merged_into');
// Ręcznie potwierdzona wpłata (Services\OrderPayment) - potrzebna do wskaźnika płatności.
$paidColumns = \Pase\Services\LowStock::columnExists($pdo, 'woo_orders', 'paid_amount') ? ' wo.paid_amount, wo.paid_amount_total,' : '';
// Zamówienie wirtualne oznaczone w CRM (bez wysyłki) - potrzebne do wskaźnika wysyłki i filtra „Do wysyłki”.
$paidColumns .= \Pase\Services\LowStock::columnExists($pdo, 'woo_orders', 'no_shipping') ? ' wo.no_shipping,' : '';
if ($hasMergeColumn) {
    $where[] = 'wo.merged_into IS NULL';
}

if ($fUnread) {
    // Ograniczamy do zamówień z mapy nieprzeczytanych; pusta mapa = brak wyników.
    $ids = array_map('intval', array_keys($unreadByOrder));
    if ($ids === []) {
        $where[] = '1 = 0';
    } else {
        $where[] = 'wo.woo_order_id IN (' . implode(',', $ids) . ')';
    }
}

// Kolumny prefiksowane aliasem wo. - oba zapytania (count i lista) używają tego
// samego aliasu, dzięki czemu JOIN z integration_accounts nie powoduje
// niejednoznaczności kolumn.
if ($q !== '') {
    $where[] = '(wo.order_number LIKE :q1 OR wo.customer_name LIKE :q2 OR wo.customer_email LIKE :q3'
        // Numer dołączonego (scalonego) zamówienia znajduje zamówienie główne.
        . ($hasMergeColumn ? ' OR EXISTS (SELECT 1 FROM woo_orders mo WHERE mo.merged_into = wo.woo_order_id AND mo.order_number LIKE :q4)' : '') . ')';
    $like = "%{$q}%";
    if ($hasMergeColumn) {
        $params[':q4'] = $like;
    }
    $params[':q1'] = $like;
    $params[':q2'] = $like;
    $params[':q3'] = $like;
}
if ($fStatus !== '') {
    $where[] = 'wo.pase_status = :status';
    $params[':status'] = $fStatus;
}
if ($fDateFrom !== '') {
    $where[] = 'wo.date_created >= :df';
    $params[':df'] = $fDateFrom . ' 00:00:00';
}
if ($fDateTo !== '') {
    $where[] = 'wo.date_created <= :dt';
    $params[':dt'] = $fDateTo . ' 23:59:59';
}
if ($fTotalMin !== '' && is_numeric($fTotalMin)) {
    $where[] = 'wo.total >= :tmin';
    $params[':tmin'] = (float) $fTotalMin;
}
if ($fTotalMax !== '' && is_numeric($fTotalMax)) {
    $where[] = 'wo.total <= :tmax';
    $params[':tmax'] = (float) $fTotalMax;
}

foreach ([[$fInvoiceIssued, $invoiceExists], [$fReceiptIssued, $receiptExists]] as [$filter, $expression]) {
    if ($filter !== '') { $where[] = ($filter === '0' ? 'NOT ' : '') . $expression; }
}
// Źródło zamówienia. Trzy formy wartości, żeby dało się filtrować i szeroko
// ("wszystko z Allegro"), i wąsko ("to jedno konto sklepu"):
//   type:<typ>  -> cała platforma (allegro, woocommerce, ...)
//   acc:<id>    -> jedno konto integracji
//   none        -> zamówienia bez przypisanego źródła (ręczny import, stare dane, dodane ręcznie)
//   manual      -> zamówienia dodane ręcznie w CRM (Services\ManualOrders)
// Warunek na ia.type działa, bo oba zapytania (licznik i lista) robią
// LEFT JOIN integration_accounts ia - patrz niżej.
if ($fSource !== '') {
    if ($fSource === 'none') {
        $where[] = 'wo.integration_id IS NULL';
    } elseif ($fSource === 'manual') {
        $where[] = 'wo.woo_order_id BETWEEN ' . \Pase\Services\ManualOrders::ID_MIN . ' AND ' . \Pase\Services\ManualOrders::ID_MAX;
    } elseif (str_starts_with($fSource, 'acc:')) {
        $where[] = 'wo.integration_id = :src_acc';
        $params[':src_acc'] = (int) substr($fSource, 4);
    } elseif (str_starts_with($fSource, 'type:')) {
        $where[] = 'ia.type = :src_type';
        $params[':src_type'] = substr($fSource, 5);
    }
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Sortowanie po kliknięciu nagłówka kolumny (?sort=&dir=) - patrz Services\OrderListSort.
[$sortColumn, $sortDir] = \Pase\Services\OrderListSort::fromRequest($_GET);
$orderBySql = \Pase\Services\OrderListSort::orderBy($sortColumn, $sortDir);
$sortJoinSql = \Pase\Services\OrderListSort::joinSql($sortColumn, (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

// Lista źródeł do selektora w filtrach: konta integracji pogrupowane po typie.
// Bierzemy też konta nieaktywne - stare zamówienia wciąż do nich należą i bez
// tego zniknęłyby z możliwości filtrowania.
//
// Tylko integracje, które FAKTYCZNIE dostarczają zamówienia (zdolność
// Capability::ORDER_SOURCE). Kurier i księgowość mają konta w tej samej tabeli,
// ale nigdy nie są źródłem zamówienia - w filtrze byłyby wyłącznie szumem.
$sourceAccounts = [];
try {
    $orderSourceTypes = array_keys(
        \Pase\Plugin\PluginRegistry::withCapability(\Pase\Plugin\Capability::ORDER_SOURCE)
    );

    // Gdyby rejestr wtyczek był niedostępny, pytamy danych: typy, z których
    // realnie przyszły zamówienia. Lepsze to niż pusty albo zaśmiecony filtr.
    if ($orderSourceTypes === []) {
        $orderSourceTypes = $pdo
            ->query('SELECT DISTINCT ia.type FROM integration_accounts ia
                     JOIN woo_orders wo ON wo.integration_id = ia.id')
            ->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    if ($orderSourceTypes !== []) {
        $placeholders = implode(',', array_fill(0, count($orderSourceTypes), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, type, name, is_active FROM integration_accounts
             WHERE type IN ($placeholders) ORDER BY type, name"
        );
        $stmt->execute(array_values($orderSourceTypes));
        $sourceAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (\Throwable $e) {
    $sourceAccounts = [];
}

// Czy w ogóle są zamówienia bez źródła - bez tego nie ma po co pokazywać opcji.
$hasOrdersWithoutSource = false;
try {
    $stmt = $pdo->prepare('SELECT 1 FROM woo_orders WHERE lifecycle = ? AND integration_id IS NULL LIMIT 1');
    $stmt->execute([$view]);
    $hasOrdersWithoutSource = (bool) $stmt->fetchColumn();
} catch (\PDOException $e) {
    $hasOrdersWithoutSource = false;
}

// Liczniki per status w obrębie bieżącego widoku (panel boczny).
$statusCounts = [];
$ordersTotal  = 0;
try {
    $scStmt = $pdo->prepare("SELECT pase_status, COUNT(*) AS n FROM woo_orders WHERE lifecycle = ?" . ($hasMergeColumn ? ' AND merged_into IS NULL' : '') . " GROUP BY pase_status");
    $scStmt->execute([$view]);
    foreach ($scStmt->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $n) {
        $statusCounts[(string) $k] = (int) $n;
        $ordersTotal += (int) $n;
    }
} catch (\PDOException $e) {
    $statusCounts = [];
}

// --- Pobranie danych + zliczenie do paginacji ---
$rows = [];
$totalRows = 0;
$noTable = false;
$queryError = null;   // treść błędu SQL, gdy to NIE jest brak tabeli (patrz catch niżej)
$fIndicators = $fNeedInvoice || $fNeedShipping || $fShipmentTracking !== '' || $fUnpaid || $fNeedReceipt;

// Filtry po wskaźnikach (faktura/wysyłka/płatność) liczone są z payloadu w PHP
// (Pase\Services\OrderIndicators), nie w SQL - nie ma ich w osobnych kolumnach.
// Dlatego gdy aktywne, paginujemy "ręcznie": pobieramy WSZYSTKIE pasujące wg
// pozostałych filtrów (z bezpiecznym limitem), odfiltrowujemy po wskaźnikach,
// dopiero potem tniemy na strony.
const INDICATOR_FILTER_SAFETY_CAP = 5000;

try {
    if ($fIndicators) {
        $sql = "SELECT wo.woo_order_id, wo.pase_number, wo.order_number, wo.status, wo.pase_status, wo.currency, wo.total,
                       wo.customer_name, wo.customer_email, wo.date_created, wo.imported_at, wo.payload, wo.local_invoice,{$paidColumns}
                       {$invoiceExists} AS invoice_issued, {$receiptExists} AS receipt_issued, {$receiptRemote} AS receipt_remote_id, {$invoiceRemote} AS invoice_remote_id,
                       ia.type AS source_type, ia.name AS source_name, ia.config AS source_config
                FROM woo_orders wo
                LEFT JOIN integration_accounts ia ON ia.id = wo.integration_id
                {$sortJoinSql}
                {$whereSql}
                ORDER BY {$orderBySql}
                LIMIT " . INDICATOR_FILTER_SAFETY_CAP;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $allRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Przesyłki dla WSZYSTKICH pasujących zamówień (nie tylko bieżącej strony) - potrzebne do wskaźnika wysyłki.
        $shipmentsAll = [];
        if ($allRows !== []) {
            $orderIds = array_map(static fn(array $r): int => (int) $r['woo_order_id'], $allRows);
            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
            $shipmentStmt = $pdo->prepare("SELECT woo_order_id, status, tracking_status, courier_code FROM shipments WHERE woo_order_id IN ({$placeholders}) ORDER BY id");
            $shipmentStmt->execute($orderIds);
            foreach ($shipmentStmt->fetchAll(PDO::FETCH_ASSOC) as $shipment) {
                $shipmentsAll[(int) $shipment['woo_order_id']][] = $shipment;
            }
        }

        $filtered = [];
        foreach ($allRows as $r) {
            $payload = json_decode($r['payload'] ?? '{}', true);
            $ind = \Pase\Services\OrderIndicators::forOrder(
                $r, is_array($payload) ? $payload : [], $shipmentsAll[(int) $r['woo_order_id']] ?? []
            );
            if ($fNeedInvoice && ($ind['invoice']['state'] !== 'warn' || !empty($r['invoice_issued']))) { continue; }
            if ($fNeedShipping && $ind['shipping']['label'] !== 'orders.indicator.shipping_needed') { continue; }
            if ($fShipmentTracking !== '' && !\Pase\Services\OrderIndicators::matchesTracking($shipmentsAll[(int) $r['woo_order_id']] ?? [], $fShipmentTracking)) { continue; }
            if ($fUnpaid && !in_array($ind['payment']['label'], ['orders.indicator.payment_unpaid', 'orders.indicator.payment_partial', 'orders.indicator.payment_cod'], true)) { continue; }
            $ind['receipt'] = \Pase\Services\OrderIndicators::receipt(
                $r, is_array($payload) ? $payload : [], $ind['invoice'], !empty($r['invoice_issued']), !empty($r['receipt_issued'])
            );
            if ($fNeedReceipt && $ind['receipt']['state'] !== 'warn') { continue; }
            $r['_indicators'] = $ind; // cache - unikamy ponownego liczenia przy renderze wiersza
            $filtered[] = $r;
        }

        $totalRows = count($filtered);
        $pages  = max(1, (int) ceil($totalRows / $perPage));
        $page   = min($page, $pages);
        $offset = ($page - 1) * $perPage;
        $rows   = array_slice($filtered, $offset, $perPage);

        $shipmentsByOrder = [];
        foreach ($rows as $r) {
            $shipmentsByOrder[(int) $r['woo_order_id']] = $shipmentsAll[(int) $r['woo_order_id']] ?? [];
        }
    } else {
        // Liczba pasujących (do paginacji).
        // JOIN musi tu być taki sam jak w zapytaniu o listę: filtr źródła używa
        // warunku na `ia.type`, a bez złączenia MySQL nie zna tej kolumny i całe
        // zapytanie pada - filtrowanie po typie kanału (np. Allegro) kończyło się
        // wtedy błędem zamiast wynikami.
        $countStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM woo_orders wo
             LEFT JOIN integration_accounts ia ON ia.id = wo.integration_id
             {$whereSql}"
        );
        $countStmt->execute($params);
        $totalRows = (int) $countStmt->fetchColumn();

        $pages  = max(1, (int) ceil($totalRows / $perPage));
        $page   = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT wo.woo_order_id, wo.pase_number, wo.order_number, wo.status, wo.pase_status, wo.currency, wo.total,
                       wo.customer_name, wo.customer_email, wo.date_created, wo.imported_at, wo.payload, wo.local_invoice,{$paidColumns}
                       {$invoiceExists} AS invoice_issued, {$receiptExists} AS receipt_issued, {$receiptRemote} AS receipt_remote_id, {$invoiceRemote} AS invoice_remote_id,
                       ia.type AS source_type, ia.name AS source_name, ia.config AS source_config
                FROM woo_orders wo
                LEFT JOIN integration_accounts ia ON ia.id = wo.integration_id
                {$sortJoinSql}
                {$whereSql}
                ORDER BY {$orderBySql}
                LIMIT " . (int) $perPage . " OFFSET " . $offset;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Przesyłki tylko dla bieżącej strony; null = dane niedostępne.
        $shipmentsByOrder = null;
        if ($rows !== []) {
            $orderIds = array_map(static fn(array $r): int => (int) $r['woo_order_id'], $rows);
            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
            $shipmentStmt = $pdo->prepare("SELECT woo_order_id, status, tracking_status, courier_code FROM shipments WHERE woo_order_id IN ({$placeholders}) ORDER BY id");
            $shipmentStmt->execute($orderIds);
            $shipmentsByOrder = [];
            foreach ($shipmentStmt->fetchAll(PDO::FETCH_ASSOC) as $shipment) {
                $shipmentsByOrder[(int) $shipment['woo_order_id']][] = $shipment;
            }
        }
    }
} catch (\PDOException $e) {
    // NIE każdy błąd SQL to brak tabeli. Wcześniej każdy wyjątek zamieniał się
    // w komunikat "tabela woo_orders jeszcze nie istnieje" i wysyłał szukającego
    // w zupełnie złą stronę - błąd w zapytaniu filtra wyglądał jak niewykonana
    // migracja. 42S02 = brak tabeli; cokolwiek innego pokazujemy wprost.
    $noTable    = $e->getCode() === '42S02';
    $queryError = $noTable ? null : $e->getMessage();
    \Pase\Support\Logger::warn('Lista zamówień: zapytanie nie powiodło się - ' . $e->getMessage());
    $pages = 1;
    $shipmentsByOrder = null;
}

// Link do paginacji z zachowaniem filtrów.
$qs = $_GET;
$buildLink = static function (int $p) use ($qs): string {
    $qs['p'] = $p;
    return 'index.php?' . http_build_query($qs);
};
// Link sortowania z nagłówka kolumny - wraca na stronę 1, filtry zostają.
$sortLink = static function (string $column) use ($qs, $sortColumn, $sortDir): string {
    $qs['sort'] = $column;
    $qs['dir'] = \Pase\Services\OrderListSort::nextDir($column, $sortColumn, $sortDir);
    unset($qs['p']);
    return 'index.php?' . http_build_query($qs);
};
// Link zmiany liczby na stronę - wraca na stronę 1, filtry zostają.
$perPageLink = static function (int $n) use ($qs): string {
    $qs['per_page'] = $n;
    unset($qs['p']);
    return 'index.php?' . http_build_query($qs);
};

$hasActiveFilters = $q !== '' || $fStatus !== '' || $fDateFrom !== '' || $fDateTo !== ''
    || $fTotalMin !== '' || $fTotalMax !== '' || $fUnread || $fIndicators
    || $fInvoiceIssued !== '' || $fReceiptIssued !== '';
