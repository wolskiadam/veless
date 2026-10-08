<?php
declare(strict_types=1);

/**
 * Szczegóły zamówienia WooCommerce (styl BaseLinker): produkty, klient + adresy,
 * płatność i podsumowanie, link do zamówienia w sklepie. Bez surowego JSON.
 */

use Pase\Repository\IntegrationAccountRepository;
use Pase\Repository\OrderMessageRepository;
use Pase\Repository\OrderDocumentRepository;
use Pase\Repository\OrderStatusRepository;
use Pase\Repository\PrintTemplateRepository;
use Pase\Repository\SettingsRepository;
use Pase\Repository\WooOrderRepository;
use Pase\Services\OrderMessageService;
use Pase\Services\OrderStatusService;
use Pase\Services\WfirmaDocumentService;

require __DIR__ . '/auth.php';
/** @var PDO $pdo */

$config = require PASE_ROOT . '/config/config.php';
$wooOrderId = (int) ($_GET['id'] ?? 0);
$statusRepo = new OrderStatusRepository($pdo);
$statusList = $statusRepo->all();
$statusMap  = $statusRepo->map();

$msgRepo = new OrderMessageRepository($pdo);

$flashOk = $flashErr = null;
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $savedFlash = $_SESSION['order_view_flash'][$wooOrderId] ?? [];
    unset($_SESSION['order_view_flash'][$wooOrderId]);
    $flashOk = $savedFlash['ok'] ?? null;
    $flashErr = $savedFlash['error'] ?? null;
}

// --- Akcje POST (zmiana statusu / wysłanie wiadomości) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && canEdit()) {
    csrfCheck();
    $act = $_POST['action'] ?? '';
    if ($act === 'company_lookup' || $act === 'save_gus_key') {
        // Dane firmy po NIP (GUS / Biała lista VAT) do formularza danych do faktury - odpowiedź JSON.
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $lookup = new \Pase\Services\CompanyLookup(new SettingsRepository($pdo));
        if ($act === 'save_gus_key') {
            if (!isAdmin()) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Klucz GUS może ustawić tylko administrator.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $key = preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['gus_key'] ?? '')) ?? '';
            (new SettingsRepository($pdo))->setMany([\Pase\Services\CompanyLookup::SETTING_KEY => mb_substr($key, 0, 64)]);
            echo json_encode(['ok' => true, 'hasKey' => $key !== ''], JSON_UNESCAPED_UNICODE);
            exit;
        }
        try {
            $found = $lookup->byNip((string) ($_POST['nip'] ?? ''), in_array($_POST['source'] ?? '', ['gus', 'mf'], true) ? $_POST['source'] : 'auto');
            echo json_encode(['ok' => true, 'company' => $found], JSON_UNESCAPED_UNICODE);
        } catch (\RuntimeException $e) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
    if ($act === 'set_status') {
        $svc = new OrderStatusService($pdo, new IntegrationAccountRepository($pdo), $statusRepo, $config['allegro'] ?? []);
        $msg = $svc->change($wooOrderId, $_POST['pase_status'] ?? '');
        $flashOk = 'Status zaktualizowany.' . ($msg !== '' ? ' ' . $msg : '');
    } elseif ($act === 'send_message') {
        $body = trim($_POST['message_body'] ?? '');
        if ($body === '' && empty($_FILES['attachment']['name'])) {
            $flashErr = 'Wpisz treść wiadomości lub dołącz plik.';
        } else {
            try {
                $ord = (new \Pase\Repository\WooOrderRepository($pdo))->find($wooOrderId);
                $svc = new OrderMessageService($pdo, $msgRepo, new SettingsRepository($pdo));
                $file = !empty($_FILES['attachment']['name']) ? $_FILES['attachment'] : null;
                // Szablon z „Dołącz fakturę z wFirma”: PDF pobrany teraz; bez faktury wiadomość nie wychodzi.
                $invoice = null;
                $tplId = (int) ($_POST['template_id'] ?? 0);
                $tpl = $tplId > 0 ? (new \Pase\Repository\EmailTemplateRepository($pdo))->find($tplId) : null;
                if ($tpl !== null && !empty($tpl['attach_invoice'])) {
                    try {
                        $invoice = (new \Pase\Services\InvoicePdf($pdo))->forOrder($wooOrderId);
                    } catch (\RuntimeException $e) {
                        throw new \RuntimeException('Wiadomość nie została wysłana. ' . $e->getMessage());
                    }
                }
                if ($ord !== null && \Pase\Services\AllegroOrderMessage::isAllegro($ord)) {
                    // Kupujący z Allegro: treść przez Centrum wiadomości Allegro, nie e-mailem na @allegromail.pl
                    // (Allegro wycinało z takiego e-maila treść). Do wątku w CRM trafia dopiero po przyjęciu przez Allegro.
                    $att = $file !== null && is_uploaded_file((string) ($file['tmp_name'] ?? ''))
                        ? ['name' => (string) $file['name'], 'content' => (string) file_get_contents((string) $file['tmp_name'])]
                        : ($invoice !== null ? ['name' => $invoice['name'], 'content' => $invoice['content']] : null);
                    \Pase\Services\AllegroOrderMessage::send(new \Pase\Services\AllegroMessaging(\PasePlugin\Allegro\AllegroPlugin::makeClient($pdo, $config['allegro'] ?? [])), $ord, $body, $att);
                    $svc->fromStaff($ord, $body, currentUserName(), $file, $invoice, false);
                    $flashOk = $invoice !== null ? 'Wiadomość z fakturą (PDF) wysłana do kupującego na Allegro.' : 'Wiadomość wysłana do kupującego na Allegro.';
                } else {
                    $svc->fromStaff($ord ?? [], $body, currentUserName(), $file, $invoice);
                    $flashOk = $invoice !== null ? 'Wiadomość z fakturą (PDF) wysłana do klienta.' : 'Wiadomość wysłana do klienta.';
                }
            } catch (\RuntimeException $e) {
                $flashErr = $e->getMessage();
            }
        }
    } elseif ($act === 'save_items') {
        // Lokalna (PASE-only) edycja ilości/usuwania pozycji - patrz WooOrderRepository::updateLocalItems.
        $cur = $pdo->prepare('SELECT payload, local_items FROM woo_orders WHERE woo_order_id = ?');
        $cur->execute([$wooOrderId]);
        $curRow = $cur->fetch(PDO::FETCH_ASSOC);
        if ($curRow) {
            $base = $curRow['local_items'] !== null
                ? (json_decode($curRow['local_items'], true) ?: [])
                : ((json_decode($curRow['payload'], true) ?: [])['line_items'] ?? []);
            $qtyPosted   = $_POST['qty'] ?? [];
            $namePosted  = (array) ($_POST['item_name'] ?? []);
            $removeIndex = $_POST['remove_index'] ?? null;
            $removeIndex = $removeIndex !== null && $removeIndex !== '' ? (int) $removeIndex : null;

            $newItems = [];
            foreach (array_values($base) as $i => $it) {
                if ($removeIndex !== null && $i === $removeIndex) {
                    continue;
                }
                $qty = isset($qtyPosted[$i]) ? max(0, (int) $qtyPosted[$i]) : (int) ($it['quantity'] ?? 1);
                if ($qty === 0) {
                    continue; // ilość 0 = usuń pozycję
                }
                $price = (float) ($it['price'] ?? 0);
                // Nowa nazwa pozycji (tylko w CRM); pusta = bez zmian.
                $newName = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($namePosted[$i] ?? '')) ?? ''), 0, 250);
                $newItems[] = [
                    'product_id' => $it['product_id'] ?? null,
                    'name'       => $newName !== '' ? $newName : (string) ($it['name'] ?? ''),
                    'sku'        => $it['sku'] ?? null,
                    'image'      => $it['image'] ?? null,
                    'allegro_offer_id' => $it['allegro_offer_id'] ?? null,
                    'in_warehouse' => $it['in_warehouse'] ?? null,
                    'price'      => $price,
                    'quantity'   => $qty,
                    'total'      => round($price * $qty, 2),
                ];
                if (!empty($it['variation_id'])) {
                    $newItems[array_key_last($newItems)]['variation_id'] = $it['variation_id'];
                }
                // VAT ze sklepu (ceny netto + total_tax) zostaje, przeliczony na nową ilość - inaczej faktura
                // w wFirma wzięłaby kwotę netto jako brutto.
                if (isset($it['total_tax']) && is_numeric($it['total_tax'])) {
                    $oldQty = max(1, (int) ($it['quantity'] ?? 1));
                    $newItems[array_key_last($newItems)]['total_tax'] = round((float) $it['total_tax'] / $oldQty * $qty, 2);
                }
            }
            (new WooOrderRepository($pdo))->updateLocalItems($wooOrderId, $newItems);
            $flashOk = 'Zapisano zmiany w produktach (w CRM - trafią na fakturę i paragon, sklep bez zmian).';
        }
    } elseif ($act === 'tiktok_retry_tracking' && \Pase\Services\TiktokShop::isTiktok($wooOrderId)) {
        // Numer przesyłki nie przeszedł do TikTok (np. nierozpoznany kurier) - worker spróbuje od nowa.
        \Pase\Services\TiktokShop::migrate($pdo);
        (new \Pase\Services\TiktokShop($pdo))->retryTracking($wooOrderId);
        $flashOk = 'Numer przesyłki zostanie wysłany do TikTok Shop przy najbliższej synchronizacji (do ' . \Pase\Services\TiktokShop::RUN_EVERY_MIN . ' min).';
    } elseif ($act === 'set_no_shipping') {
        // Zamówienie wirtualne (bez wysyłki): tylko w CRM - znika ikona „Do wysyłki”, filtr jej nie pokazuje.
        // Odznaczenie zapisuje 0 (nie NULL): wybór z karty wygrywa z automatem produktów wirtualnych.
        $flag = ($_POST['no_shipping'] ?? '') === '1' ? 1 : 0;
        $cur = (new WooOrderRepository($pdo))->find($wooOrderId);
        if ($cur !== null && (int) ($cur['no_shipping'] ?? 0) !== (int) $flag) {
            (new \Pase\Services\AuditTrail($pdo))->changeOrder($wooOrderId, 'order.no_shipping_changed', ['no_shipping' => $flag]);
        }
        $flashOk = $flag ? 'Oznaczono jako zamówienie wirtualne - nie wymaga wysyłki.' : 'Zamówienie znów wymaga wysyłki.';
    } elseif ($act === 'set_manual_shop') {
        // Sklep zamówienia dodanego ręcznie - tylko w CRM (konto kurierskie / nadawca, CourierShipping::servesShop).
        $shopId = (int) ($_POST['shop_id'] ?? 0);
        $shopList = \Pase\Services\ManualOrders::shops($pdo);
        if ($shopId > 0 && !isset($shopList[$shopId])) {
            $flashErr = 'Nie ma takiego sklepu.';
        } else {
            (new \Pase\Services\ManualOrders($pdo))->setShop($wooOrderId, $shopId > 0 ? $shopId : null);
            $flashOk = $shopId > 0 ? 'Sklep zamówienia: ' . $shopList[$shopId] . '.' : 'Zamówienie bez przypisanego sklepu.';
        }
    } elseif ($act === 'set_planned_ship_date') {
        // Planowana data nadania - tylko w CRM; klient widzi ją na swojej stronie zamówienia (Services\PlannedShipDate).
        try {
            $date = ($_POST['planned_reset'] ?? '') === '1' ? null
                : \Pase\Services\PlannedShipDate::parse((string) ($_POST['planned_ship_date'] ?? ''));
            (new \Pase\Services\PlannedShipDate($pdo))->set($wooOrderId, $date);
            $flashOk = $date === null ? 'Usunięto planowaną datę nadania.'
                : 'Planowana data nadania: ' . date('d.m.Y', strtotime($date)) . ' - klient zobaczy ją na swojej stronie zamówienia.';
        } catch (\Throwable $e) {
            $flashErr = 'Data nadania: ' . $e->getMessage();
        }
    } elseif ($act === 'add_item') {
        $sku = trim($_POST['add_sku'] ?? '');
        $qty = max(1, (int) ($_POST['add_qty'] ?? 1));
        if ($sku === '') {
            $flashErr = 'Wybierz produkt do dodania (z listy podpowiedzi po SKU/nazwie).';
        } else {
            $p = $pdo->prepare('SELECT id, sku, name, pase_price FROM products WHERE sku = ? LIMIT 1');
            $p->execute([$sku]);
            $prod = $p->fetch(PDO::FETCH_ASSOC);
            if (!$prod) {
                $flashErr = 'Nie znaleziono produktu o SKU „' . $sku . '” w magazynie CRM.';
            } else {
                $cur = $pdo->prepare('SELECT payload, local_items FROM woo_orders WHERE woo_order_id = ?');
                $cur->execute([$wooOrderId]);
                $curRow = $cur->fetch(PDO::FETCH_ASSOC);
                $base = $curRow['local_items'] !== null
                    ? (json_decode($curRow['local_items'], true) ?: [])
                    : ((json_decode($curRow['payload'], true) ?: [])['line_items'] ?? []);
                $price = (float) ($prod['pase_price'] ?? 0);
                $base[] = [
                    'product_id' => (int) $prod['id'],
                    'name'       => (string) $prod['name'],
                    'sku'        => $prod['sku'],
                    'price'      => $price,
                    'quantity'   => $qty,
                    'total'      => round($price * $qty, 2),
                ];
                (new WooOrderRepository($pdo))->updateLocalItems($wooOrderId, $base);
                $flashOk = 'Dodano produkt „' . $prod['name'] . '” do zamówienia (lokalnie w CRM).';
            }
        }
    } elseif ($act === 'revert_items') {
        (new WooOrderRepository($pdo))->clearLocalItems($wooOrderId);
        $flashOk = 'Przywrócono oryginalne pozycje ze sklepu.';
    } elseif ($act === 'save_pickup_point') {
        // Punkt odbioru poprawiony ręcznie (np. klient zmienił punkt) - pusty ID = wróć do danych z zamówienia.
        $pid = mb_substr(trim((string) ($_POST['pp_id'] ?? '')), 0, 40);
        $ppJson = $pid === '' ? null : json_encode([
            'id' => $pid,
            'name' => mb_substr(trim((string) ($_POST['pp_name'] ?? '')), 0, 120),
            'street' => mb_substr(trim((string) ($_POST['pp_street'] ?? '')), 0, 160),
            'postcode' => mb_substr(trim((string) ($_POST['pp_postcode'] ?? '')), 0, 12),
            'city' => mb_substr(trim((string) ($_POST['pp_city'] ?? '')), 0, 80),
        ], JSON_UNESCAPED_UNICODE);
        $pdo->prepare('UPDATE woo_orders SET local_pickup_point = ? WHERE woo_order_id = ?')->execute([$ppJson, $wooOrderId]);
        $flashOk = $pid === '' ? 'Przywrócono punkt odbioru z zamówienia.' : 'Zapisano punkt odbioru.';
    } elseif ($act === 'refresh_allegro_delivery') {
        // Ponowne pobranie danych dostawy z Allegro (np. kupujący zmienił punkt po zakupie).
        $cur = (new \Pase\Repository\WooOrderRepository($pdo))->find($wooOrderId);
        $curPayload = json_decode((string) ($cur['payload'] ?? ''), true) ?: [];
        $cfId = (string) ($curPayload['id'] ?? $cur['order_number'] ?? '');
        $fresh = $cfId !== '' ? \PasePlugin\Allegro\AllegroPlugin::makeClient($pdo, $config['allegro'] ?? [])->checkoutForm($cfId) : null;
        if (!is_array($fresh)) {
            $flashErr = 'Nie udało się pobrać zamówienia z Allegro (sprawdź połączenie konta).';
        } else {
            foreach (['delivery', 'buyer', 'invoice'] as $k) {
                if (isset($fresh[$k])) {
                    $curPayload[$k] = $fresh[$k];
                }
            }
            $pdo->prepare('UPDATE woo_orders SET payload = ? WHERE woo_order_id = ?')
                ->execute([json_encode($curPayload, JSON_UNESCAPED_UNICODE), $wooOrderId]);
            $flashOk = !empty($fresh['delivery']['pickupPoint']['id'])
                ? 'Pobrano z Allegro: punkt odbioru ' . $fresh['delivery']['pickupPoint']['id'] . '.'
                : 'Pobrano dane dostawy z Allegro — to zamówienie nie ma punktu odbioru (dostawa pod adres).';
        }
    } elseif ($act === 'refresh_woo_delivery') {
        // Ponowne pobranie zamówienia ze sklepu (np. wtyczka dopisała punkt odbioru już po imporcie).
        // Podmieniamy tylko dane dostawy i meta - pozycje, zdjęcia i poprawki z CRM zostają.
        try {
            $cur = (new \Pase\Repository\WooOrderRepository($pdo))->find($wooOrderId);
            $curPayload = json_decode((string) ($cur['payload'] ?? ''), true) ?: [];
            $acc = !empty($cur['integration_id']) ? (new \Pase\Repository\IntegrationAccountRepository($pdo))->find((int) $cur['integration_id']) : null;
            $shopId = (int) ($curPayload['id'] ?? 0);
            if ($acc === null || ($acc['type'] ?? '') !== 'woocommerce' || $shopId <= 0) {
                throw new \RuntimeException('To zamówienie nie jest powiązane ze sklepem WooCommerce — nie ma skąd go pobrać.');
            }
            $fresh = \Pase\Plugin\IntegrationClient::for('woocommerce', $acc['config'] ?? [])?->fetchOrder($shopId);
            if (!is_array($fresh)) {
                throw new \RuntimeException("Sklep „{$acc['name']}” nie zwrócił zamówienia #{$shopId}.");
            }
            foreach (['shipping', 'shipping_lines', 'meta_data'] as $k) {
                if (isset($fresh[$k])) {
                    $curPayload[$k] = $fresh[$k];
                }
            }
            $pdo->prepare('UPDATE woo_orders SET payload = ? WHERE woo_order_id = ?')
                ->execute([json_encode($curPayload, JSON_UNESCAPED_UNICODE), $wooOrderId]);
            $got = \Pase\Support\PickupPoint::fromOrder($curPayload);
            $flashOk = $got !== null
                ? 'Pobrano ze sklepu: punkt odbioru ' . $got['id'] . '.'
                : 'Pobrano dane dostawy ze sklepu — nie ma w nich punktu odbioru.';
        } catch (\Throwable $e) {
            $flashErr = $e->getMessage();
        }
    } elseif ($act === 'save_invoice_data' || $act === 'restore_invoice_data') {
        // Poprawione dane do faktury - tylko w CRM (faktura w wFirma, wydruki, wskaźnik na liście).
        $repoInv = new WooOrderRepository($pdo);
        if ($act === 'restore_invoice_data') {
            $repoInv->updateLocalInvoice($wooOrderId, null);
            $flashOk = 'Przywrócono dane do faktury z zamówienia.';
        } else {
            $form = \Pase\Services\InvoiceData::fromForm($_POST);
            if ($form['errors'] !== []) {
                $flashErr = implode(' ', $form['errors']) . ' Nie zapisano.';
            } else {
                $repoInv->updateLocalInvoice($wooOrderId, $form['json']);
                $flashOk = 'Zapisano dane do faktury (w CRM — sklep ich nie dostaje).';
            }
        }
    } elseif ($act === 'save_contact') {
        // Lokalna korekta e-maila/telefonu (np. literówka klienta) - patrz updateLocalContact().
        $emailIn = trim($_POST['contact_email'] ?? '');
        $phoneIn = trim($_POST['contact_phone'] ?? '');
        if ($emailIn !== '' && !filter_var($emailIn, FILTER_VALIDATE_EMAIL)) {
            $flashErr = 'Nieprawidłowy adres e-mail.';
        } else {
            (new WooOrderRepository($pdo))->updateLocalContact($wooOrderId, $emailIn, $phoneIn);
            try {
                // Poprawiony e-mail / telefon może przenieść zamówienie do innego klienta.
                (new \Pase\Services\Customers($pdo))->refreshOrder($wooOrderId);
            } catch (\Throwable $e) {
                \Pase\Support\Logger::warn('Klienci: przeliczenie po zmianie kontaktu nie powiodło się - ' . $e->getMessage());
            }
            $flashOk = ($emailIn === '' && $phoneIn === '')
                ? 'Przywrócono oryginalne dane kontaktowe ze sklepu.'
                : 'Zapisano poprawkę danych kontaktowych (lokalnie w CRM, nie wysłano do sklepu).';
        }
    } elseif ($act === 'issue_wfirma_document') {
        $documentType = (string) ($_POST['document_type'] ?? '');
        $documentLabels = [
            'normal' => 'Fakturę',
            'receipt' => 'Paragon niefiskalny',
            'receipt_fiscal' => 'Paragon fiskalny',
            'proforma' => 'Proformę',
        ];
        try {
            $result = (new WfirmaDocumentService($pdo))->issue($wooOrderId, $documentType);
            $label = $documentLabels[$documentType] ?? 'Dokument';
            $flashOk = $label . ' wystawiono w wFirma (ID: ' . $result['document_id'] . ').';
        } catch (\Throwable $e) {
            $flashErr = 'Nie udało się wystawić dokumentu: ' . $e->getMessage();
        }
    } elseif ($act === 'delete_wfirma_receipt') {
        try {
            (new WfirmaDocumentService($pdo))->deleteReceipt($wooOrderId, (int) ($_POST['document_id'] ?? 0));
            $flashOk = 'Usunięto paragon z wFirma i jego lokalne powiązanie. Możesz wystawić go ponownie.';
        } catch (\Throwable $e) {
            $flashErr = 'Usuwanie paragonu: ' . $e->getMessage();
        }
    } elseif ($act === 'unlink_wfirma_receipt') {
        $removed = (new OrderDocumentRepository($pdo))->unlinkReceipt(
            (int) ($_POST['document_id'] ?? 0), $wooOrderId
        );
        if ($removed) {
            $flashOk = 'Usunięto lokalne powiązanie paragonu. Możesz wystawić go ponownie. Dokument w wFirma nie został zmieniony.';
        } else {
            $flashErr = 'Nie znaleziono powiązania paragonu z tym zamówieniem. Być może zostało już usunięte.';
        }
    } elseif ($act === 'set_paid_amount') {
        // Ręczne potwierdzenie wpłaty - tylko w CRM, nic nie idzie do sklepu / Allegro (Services\OrderPayment).
        try {
            \Pase\Services\OrderPayment::migrate($pdo);
            $raw = trim((string) ($_POST['paid_amount'] ?? ''));
            if (($_POST['paid_reset'] ?? '') === '1') {
                (new \Pase\Services\OrderPayment($pdo))->set($wooOrderId, null, currentUserName());
                $flashOk = 'Usunięto ręczną wpłatę - status płatności znów pochodzi ze sklepu.';
            } else {
                $norm = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], $raw);
                if (!preg_match('/^\d+(\.\d{1,2})?$/', $norm)) {
                    throw new \RuntimeException('Podaj kwotę wpłaty, np. 44.98.');
                }
                $saved = (new \Pase\Services\OrderPayment($pdo))->set($wooOrderId, (float) $norm, currentUserName());
                $flashOk = 'Zapisano wpłatę ' . number_format((float) $saved, 2, '.', '') . ' (tylko w CRM, sklep bez zmian).';
            }
        } catch (\Throwable $e) {
            $flashErr = 'Wpłata: ' . $e->getMessage();
        }
    } elseif ($act === 'payu_create_link') {
        // Link do płatności PayU (PayuPayments::createLink) - nowa płatność w PayU, link do skopiowania; nic nie idzie do klienta.
        try {
            \Pase\Services\PayuPayments::migrate($pdo);
            $ordRow = (new WooOrderRepository($pdo))->find($wooOrderId);
            if ($ordRow === null || !\Pase\Services\PayuPayments::eligible($ordRow)) {
                throw new \RuntimeException('Dla tego zamówienia nie można utworzyć linku PayU.');
            }
            if (!empty($ordRow['merged_into'])) {
                throw new \RuntimeException('To zamówienie jest scalone - link utwórz na zamówieniu głównym.');
            }
            $norm = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim((string) ($_POST['link_amount'] ?? '')));
            if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $norm) || (float) $norm <= 0) {
                throw new \RuntimeException('Podaj kwotę linku, np. 44.98.');
            }
            $pl = (new \Pase\Services\PayuPayments($pdo, new \Pase\Services\PayuPayouts($pdo)))->createLink(
                $wooOrderId, (int) ($_POST['link_shop'] ?? 0), (int) round((float) $norm * 100), (string) ($_POST['link_description'] ?? ''),
                (int) ($_POST['link_days'] ?? 7), (string) ($_POST['link_ext'] ?? ''), currentUserName(), \Pase\Services\PayuPayments::linkOptions($pdo, $ordRow));
            $flashOk = 'Utworzono link do płatności PayU na ' . number_format((int) $pl['amount'] / 100, 2, '.', '') . ' PLN - skopiuj go i wyślij klientowi.';
        } catch (\Throwable $e) {
            $flashErr = 'Link PayU: ' . $e->getMessage();
        }
    } elseif (in_array($act, ['payu_link', 'payu_unlink', 'payu_refresh'], true)) {
        // Płatność PayU przy zamówieniu (Services\PayuPayments): przypisanie tylko czyta PayU, nic tam nie zmienia.
        try {
            if (!canEdit()) {
                throw new \RuntimeException('Brak uprawnień.');
            }
            \Pase\Services\PayuPayments::migrate($pdo);
            $payuSvc = new \Pase\Services\PayuPayments($pdo, new \Pase\Services\PayuPayouts($pdo));
            if ($act === 'payu_link') {
                $pl = $payuSvc->link($wooOrderId, (string) ($_POST['payu_order_id'] ?? ''), currentUserName());
                $flashOk = 'Przypisano płatność PayU ' . $pl['payu_order_id'] . ' (' . \Pase\Services\PayuPayments::statusLabel((string) $pl['status']) . ').';
            } elseif ($act === 'payu_unlink') {
                $payuSvc->unlink($wooOrderId, (int) ($_POST['payment_id'] ?? 0));
                $flashOk = 'Odłączono płatność PayU od zamówienia (w PayU nic się nie zmieniło).';
            } else {
                $pl = $payuSvc->payment((int) ($_POST['payment_id'] ?? 0));
                if ($pl === null || (int) $pl['woo_order_id'] !== $wooOrderId) {
                    throw new \RuntimeException('Nie ma takiej płatności przy tym zamówieniu.');
                }
                $payuSvc->refresh((int) $pl['id']);
                $flashOk = 'Odświeżono płatność PayU.';
            }
        } catch (\Throwable $e) {
            $flashErr = 'PayU: ' . $e->getMessage();
        }
    } elseif ($act === 'delete_message') {
        // Usunięcie WŁASNEJ wiadomości obsługi (tylko sender='staff', w obrębie zamówienia).
        $deleted = $msgRepo->deleteStaffMessage((int) ($_POST['message_id'] ?? 0), $wooOrderId);
        if ($deleted !== null) {
            if (!empty($deleted['attachment_path'])) {
                $full = \Pase\Services\Attachments::absolutePath($deleted['attachment_path']);
                if ($full !== null && is_file($full)) {
                    @unlink($full);
                }
            }
            $flashOk = 'Wiadomość usunięta.';
        } else {
            $flashErr = 'Nie można usunąć tej wiadomości.';
        }
    }
}

// POST → redirect → GET: odświeżenie nie ponawia operacji formularza.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['order_view_flash'][$wooOrderId] = ['ok' => $flashOk, 'error' => $flashErr];
    session_write_close();
    header('Location: order_view.php?id=' . $wooOrderId, true, 303);
    exit;
}

// Oznacz wiadomości klienta jako przeczytane przy otwarciu zamówienia.
$msgRepo->markClientRead($wooOrderId);
// Otwarte zamówienie znika z „Nowych zamówień" w dzwoneczku tego użytkownika.
try { (new \Pase\Services\Notifications($pdo))->dismissOrder((int) (currentUserId() ?? 0), $wooOrderId); } catch (\Throwable) {}
$thread = $msgRepo->thread($wooOrderId);
// Maile z automatyzacji (dziennik e-maili) w wątku jako wiadomości automatyczne. Dziennik nie trzyma
// treści maila, więc pokazujemy temat i szablon. Czas w dzienniku jest w UTC - zamieniamy na polski.
try {
    $autoMails = (new \Pase\Services\EmailLog($pdo))->forOrder($wooOrderId);
    if ($autoMails !== []) {
        $tplRepo = new \Pase\Repository\EmailTemplateRepository($pdo);
        $tplNames = [];
        $plZone = new DateTimeZone('Europe/Warsaw');
        foreach ($autoMails as $am) {
            $key = (string) ($am['template_key'] ?? '');
            if ($key !== '' && !array_key_exists($key, $tplNames)) {
                $tplNames[$key] = (string) ($tplRepo->findByKey($key)['name'] ?? $key);
            }
            $thread[] = [
                'id'         => 0,
                'sender'     => 'auto',
                'author_name'=> 'Wiadomość automatyczna',
                'body'       => (string) $am['subject'],
                'created_at' => (new DateTimeImmutable((string) $am['created_at'], new DateTimeZone('UTC')))->setTimezone($plZone)->format('Y-m-d H:i:s'),
                'template'   => $key !== '' ? $tplNames[$key] : '',
                'recipient'  => (string) $am['recipient'],
                'failed'     => $am['status'] !== 'sent',
                'error'      => (string) ($am['error'] ?? ''),
            ];
        }
        usort($thread, static fn(array $a, array $b): int => strcmp((string) $a['created_at'], (string) $b['created_at']));
    }
} catch (\Throwable) {
    // starsza baza bez dziennika e-maili - wątek bez wiadomości automatycznych
}

$stmt = $pdo->prepare('SELECT * FROM woo_orders WHERE woo_order_id = ?');
$stmt->execute([$wooOrderId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    $PAGE_TITLE = 'Zamówienie';
    $PAGE_KEY = 'orders';
    require __DIR__ . '/header.php';
    echo '<div class="flash err">Nie znaleziono zamówienia.</div>';
    echo '<p><a class="btn secondary" href="index.php">← Powrót do listy</a></p>';
    require __DIR__ . '/footer.php';
    return;
}

$o = json_decode($row['payload'], true) ?: [];
$isManualOrder = \Pase\Services\ManualOrders::isManual($wooOrderId);   // dodane ręcznie w CRM - tylko w CRM

// Czy sync do Woo jest włączony dla integracji TEGO zamówienia.
$intRepo = new IntegrationAccountRepository($pdo);
$syncEnabled = false;
$sourceType  = null;
if (!empty($row['integration_id'])) {
    $intg = $intRepo->find((int) $row['integration_id']);
    $syncEnabled = $intg !== null && !empty($intg['config']['sync_status']);
    $sourceType  = $intg['type'] ?? null;
}

// BLPaczka: czy jest aktywna integracja kurierska + już nadane przesyłki.
$blInteg = $intRepo->firstActive('blpaczka');
$hasBlpaczka = $blInteg !== null;
$wfirmaInteg = $intRepo->firstActive('wfirma');
$orderDocuments = (new OrderDocumentRepository($pdo))->forOrder($wooOrderId);
// Scalone zamówienie: dokument (faktura / paragon) wystawia się na zamówieniu głównym dla całej paczki,
// więc dołączone pokazuje dokumenty głównego (chyba że miało własny dokument sprzed scalenia).
$docsFromMain = null;
if (!empty($row['merged_into']) && $orderDocuments === []) {
    $docsFromMain = (int) $row['merged_into'];
    $orderDocuments = (new OrderDocumentRepository($pdo))->forOrder($docsFromMain);
}
$orderReturns = (new \Pase\Services\OrderReturns($pdo))->forOrder($wooOrderId);
// Karta klienta: ile razy ta osoba już kupowała i gdzie (Klienci → customers.php).
$orderCustomer = null;
if (canViewPage('customers')) {
    try {
        $customersSvc = new \Pase\Services\Customers($pdo);
        $orderCustomerId = $customersSvc->customerIdForOrder($wooOrderId);
        $orderCustomer = $orderCustomerId !== null ? $customersSvc->find($orderCustomerId) : null;
    } catch (\Throwable $e) {
        \Pase\Support\Logger::warn('Klienci: nie udało się ustalić klienta zamówienia - ' . $e->getMessage());
    }
}
$orderFeedback = (new \Pase\Services\AllegroFeedback($pdo))->forOrder($wooOrderId);
$documentTypesIssued = array_fill_keys(array_column($orderDocuments, 'document_type'), true);
// Paragon: każde zamówienie bez faktury musi go mieć (ten sam wskaźnik co na liście zamówień).
$issuedDocs = array_column(array_filter($orderDocuments, static fn(array $d): bool => ($d['status'] ?? '') === 'issued'), 'document_type');
$invoiceIndicator = \Pase\Services\OrderIndicators::forOrder($row, $o, null)['invoice'];
// Faktura do wystawienia: klient prosi o fakturę albo podał NIP, a faktury z CRM jeszcze nie ma.
$invoiceNeeded = $invoiceIndicator['state'] === 'warn' && !in_array('normal', $issuedDocs, true);
$receiptIndicator = \Pase\Services\OrderIndicators::receipt(
    $row, $o, $invoiceIndicator,
    in_array('normal', $issuedDocs, true), array_intersect(['receipt', 'receipt_fiscal'], $issuedDocs) !== []
);
// Domyślny format etykiety z integracji (A4 lub LBL); fallback A4.
// Globalne ustawienia druku (zakładka Drukowanie) z fallbackiem na integrację.
$printCfg = (new \Pase\Repository\SettingsRepository($pdo))->all();
$defaultLabelFmt = strtoupper($printCfg['PRINT_LABEL_FORMAT'] ?? ($blInteg['config']['label_format'] ?? 'A4'));
if (!in_array($defaultLabelFmt, ['A4', 'LBL', 'ZPL', 'EPL'], true)) { $defaultLabelFmt = 'A4'; }
$defPkg = [
    'weight' => $printCfg['PRINT_DEF_WEIGHT'] ?? '1',
    'x'      => $printCfg['PRINT_DEF_X'] ?? '20',
    'y'      => $printCfg['PRINT_DEF_Y'] ?? '15',
    'z'      => $printCfg['PRINT_DEF_Z'] ?? '10',
];
$shipments = (new \Pase\Repository\ShipmentRepository($pdo))->forOrder($wooOrderId);
// TikTok Shop: czy numer przesyłki poszedł do TikTok (Services\TiktokShop::pushTracking).
$tiktokLink = null;
if (\Pase\Services\TiktokShop::isTiktok($wooOrderId)) {
    try {
        \Pase\Services\TiktokShop::migrate($pdo);
        $tiktokLink = (new \Pase\Services\TiktokShop($pdo))->orderLink($wooOrderId);
    } catch (\Throwable $e) {
        $tiktokLink = null;
    }
}

// Wtyczki kurierskie: przesyłki, których numer przewoźnik jeszcze liczył (np. „Wysyłam z Allegro"),
// dokańczamy przy otwarciu zamówienia; do tego lista „czym nadać" dla tego zamówienia.
$courierSvc = new \Pase\Services\CourierShipping($pdo);
try {
    if ($courierSvc->resolvePending($shipments)) {
        $shipments = (new \Pase\Repository\ShipmentRepository($pdo))->forOrder($wooOrderId);
    }
} catch (\Throwable $e) {
    \Pase\Support\Logger::warn('Kurier: dokończenie przesyłek nie powiodło się - ' . $e->getMessage());
}
$courierOrder = ['row' => $row, 'payload' => json_decode((string) ($row['payload'] ?? ''), true) ?: []];
try {
    // Faktyczny koszt nadanych przesyłek (np. z rozliczeń Allegro) - gdy już jest dostępny.
    if ($courierSvc->fillActualCosts($shipments, $courierOrder)) {
        $shipments = (new \Pase\Repository\ShipmentRepository($pdo))->forOrder($wooOrderId);
    }
} catch (\Throwable $e) {
    \Pase\Support\Logger::warn('Kurier: pobranie kosztu przesyłki nie powiodło się - ' . $e->getMessage());
}
$courierOptions = $courierSvc->accountsForOrder($courierOrder);

// Skróty bezpiecznego dostępu do pól.
$g = static fn(array $a, string $k, $def = ''): string => htmlspecialchars((string)($a[$k] ?? $def));

$allegroPickupPoint = null;
$allegroDeliveryMethod = null;
if ($sourceType === 'allegro') {
    // Allegro ma inny kształt payloadu niż Woo (buyer/delivery zamiast billing/shipping) -
    // mapujemy na te same klucze, żeby cała reszta strony (adresy, etykiety, faktura)
    // działała bez zmian niezależnie od źródła zamówienia.
    $buyer = $o['buyer'] ?? [];
    $deliveryAddr = $o['delivery']['address'] ?? [];
    $mapAllegroAddr = static function (array $addr, array $buyer): array {
        return [
            'first_name' => $addr['firstName'] ?? ($buyer['firstName'] ?? ''),
            'last_name'  => $addr['lastName'] ?? ($buyer['lastName'] ?? ''),
            'company'    => is_array($buyer['companyName'] ?? null) ? ($buyer['companyName']['name'] ?? '') : '',
            'address_1'  => $addr['street'] ?? '',
            'city'       => $addr['city'] ?? '',
            'postcode'   => $addr['zipCode'] ?? '',
            'country'    => $addr['countryCode'] ?? 'PL',
            'email'      => $buyer['email'] ?? '',
            'phone'      => $addr['phoneNumber'] ?? ($buyer['phoneNumber'] ?? ''),
        ];
    };
    $billing  = $mapAllegroAddr($buyer['address'] ?? $deliveryAddr, $buyer);
    $shipping = $deliveryAddr !== [] ? $mapAllegroAddr($deliveryAddr, $buyer) : [];

    $allegroDeliveryMethod = $o['delivery']['method']['name'] ?? null;
    $pp = $o['delivery']['pickupPoint'] ?? null;
    if (is_array($pp) && $pp !== []) {
        $ppAddr = $pp['address'] ?? [];
        $allegroPickupPoint = [
            'id'   => $pp['id'] ?? '',
            'name' => $pp['name'] ?? ($pp['description'] ?? ''),
            'line' => trim(($ppAddr['street'] ?? '') . ', ' . ($ppAddr['zipCode'] ?? '') . ' ' . ($ppAddr['city'] ?? ''), ' ,'),
        ];
    }
} else {
    $billing  = $o['billing'] ?? [];
    $shipping = $o['shipping'] ?? [];
}
// Punkt odbioru (paczkomat, automat ORLEN Paczka...): ręczna poprawka w CRM > Allegro > meta sklepu.
$pickupPoint = \Pase\Support\PickupPoint::fromOrder($o, json_decode((string) ($row['local_pickup_point'] ?? ''), true) ?: null);
$pickupEdited = !empty($row['local_pickup_point']);

// Pozycje zamówienia: lokalna edycja PASE (jeśli istnieje) ma pierwszeństwo przed
// oryginałem ze sklepu - patrz WooOrderRepository::updateLocalItems.
$isLocallyEdited = $row['local_items'] !== null;
$items = $isLocallyEdited ? (json_decode($row['local_items'], true) ?: []) : ($o['line_items'] ?? []);
// Miniatury: magazyn CRM, a dla ofert spoza magazynu - główne zdjęcie oferty Allegro.
// Zdjęcia pobrane z Allegro zapisujemy w payloadzie, żeby API pytać tylko raz.
$thumbAllegro = null;
if ($sourceType === 'allegro') {
    try {
        $thumbAllegro = \PasePlugin\Allegro\AllegroPlugin::makeClient($pdo, $config['allegro'] ?? []);
    } catch (\Throwable $e) {
        $thumbAllegro = null;
    }
}
$thumbSvc = new \Pase\Services\OrderItemThumbnails($pdo, $thumbAllegro);
$itemThumbs = $thumbSvc->forItems($items);
if ($thumbSvc->fetchedFromAllegro !== [] && !$isLocallyEdited && isset($o['line_items'])) {
    foreach ($thumbSvc->fetchedFromAllegro as $i => $url) {
        if (isset($o['line_items'][$i])) {
            $o['line_items'][$i]['image'] = $url;
        }
    }
    $pdo->prepare('UPDATE woo_orders SET payload = ? WHERE woo_order_id = ?')
        ->execute([json_encode($o, JSON_UNESCAPED_UNICODE), $wooOrderId]);
}

$localTotal = $isLocallyEdited
    ? array_sum(array_map(static fn($it) => (float) ($it['total'] ?? 0), $items))
    : (float) ($o['total'] ?? 0);

// Scalanie zamówień (Services\OrderMerge): dołączone zamówienia idą w paczce tego zamówienia.
$mergedTarget = null;
$absorbedOrders = [];
$mergedExtraItems = [];
$groupTotal = $localTotal;
try {
    $orderMerge = new \Pase\Services\OrderMerge($pdo);
    $mergedTarget = !empty($row['merged_into']) ? $orderMerge->targetOf($wooOrderId) : null;
    $absorbedOrders = $orderMerge->absorbed($wooOrderId);
    $mergedExtraItems = $orderMerge->extraItems($wooOrderId);
    foreach ($absorbedOrders as $ab) {
        $groupTotal += \Pase\Services\OrderMerge::orderTotal($ab);
    }
} catch (\Throwable $e) {
    \Pase\Support\Logger::warn('Scalanie: nie udało się odczytać scalonych zamówień - ' . $e->getMessage());
}
$mergedThumbs = $mergedExtraItems !== [] ? $thumbSvc->forItems($mergedExtraItems) : [];

// Kontakt: lokalna korekta (jeśli jest) ma pierwszeństwo przed oryginałem ze sklepu.
// Dotyczy tylko wyświetlania w PASE - NIE zmienia danych w sklepie ani weryfikacji klienta.
$isContactEdited = ($row['local_billing_email'] ?? null) !== null || ($row['local_billing_phone'] ?? null) !== null;
$contactEmail = $row['local_billing_email'] ?? ($billing['email'] ?? '');
$contactPhone = $row['local_billing_phone'] ?? ($billing['phone'] ?? '');

// Katalog produktów PASE do podpowiedzi przy dodawaniu pozycji (datalist, bez AJAX).
$catalog = [];
try {
    $catalog = $pdo->query(
        "SELECT sku, name, pase_price FROM products WHERE sku IS NOT NULL AND sku <> '' ORDER BY name LIMIT 2000"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $catalog = [];
}

// Aktywne szablony wydruku - lista w dropdownie "Drukuj / Eksportuj" poniżej.
$printTemplates = [];
try {
    $printTemplates = (new PrintTemplateRepository($pdo))->active();
} catch (\Throwable $e) {
    $printTemplates = [];
}

// Czy adres dostawy jest pusty? (Woo zostawia puste pola gdy = dane faktury)
$hasShipping = trim(($shipping['address_1'] ?? '') . ($shipping['city'] ?? '')) !== '';

// Pełny adres jako blok.
$fmtAddr = static function (array $a) use ($g): string {
    $name = trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''));
    $lines = array_filter([
        $name,
        $a['company'] ?? '',
        trim(($a['address_1'] ?? '') . ' ' . ($a['address_2'] ?? '')),
        trim(($a['postcode'] ?? '') . ' ' . ($a['city'] ?? '')),
        $a['country'] ?? '',
    ]);
    return implode('<br>', array_map(fn($l) => htmlspecialchars($l), $lines)) ?: '—';
};

$currency = $o['currency'] ?? $row['currency'] ?? 'PLN';

// Potwierdzenie płatności pochodzi z danych sklepu, nie ze statusu realizacji PASE.
$paymentDate = $o['date_paid'] ?? $o['date_paid_gmt'] ?? null;
$paymentDateIsUtc = empty($o['date_paid']) && !empty($o['date_paid_gmt']);
if (empty($paymentDate) && !empty($o['date_paid_gmt'])) {
    $paymentDate = $o['date_paid_gmt'];
}
$paymentLabel = 'Brak danych';
$paymentClass = 'muted';
// Ręcznie potwierdzona wpłata (OrderPayment) - liczona względem aktualnej sumy (ze scalonymi).
$manualPaid = \Pase\Services\OrderPayment::state($row, $groupTotal);
if (($o['status'] ?? $row['status'] ?? '') === 'refunded') {
    $paymentLabel = 'Zwrócono płatność';
} elseif (\Pase\Services\PaymentFailure::failed($row, $o)) {
    $paymentLabel = 'Płatność nieudana';
    $paymentClass = 'bad';
    $paymentDate = null;
} elseif ($manualPaid !== null) {
    $paymentLabel = $manualPaid['state'] === 'ok' ? 'Opłacone' : ($manualPaid['amount'] > 0 ? 'Opłacone częściowo' : 'Nieopłacone');
    $paymentClass = $manualPaid['state'];
    $paymentDate = $manualPaid['state'] === 'ok' ? ($row['paid_amount_at'] ?? null) : null;
    $paymentDateIsUtc = true;
} elseif (!empty($paymentDate)) {
    $paymentLabel = 'Opłacone';
    $paymentClass = 'ok';
} elseif (array_key_exists('date_paid', $o) || array_key_exists('date_paid_gmt', $o)) {
    $paymentLabel = ($o['payment_method'] ?? '') === 'cod' ? 'Za pobraniem — brak potwierdzenia wpłaty' : 'Nieopłacone';
    $paymentClass = 'warn';
}

// Link do zamówienia w panelu WooCommerce (base_url z integracji zamówienia).
$wooBase = $intg['config']['base_url'] ?? '';
$adminLink = $wooBase !== '' ? "{$wooBase}/wp-admin/post.php?post={$wooOrderId}&action=edit" : null;

// Płatność PayU (wtyczka PayU + zamówienie ze sklepu): automatyczne przypisanie po numerze ze sklepu, odświeżenie w toku.
$payuOn = \Pase\Services\PayuPayouts::available() && \Pase\Services\PayuPayments::eligible($row);
$payuPayments = $payuRefunds = $payuLinks = [];
$payuSyncError = null;
$payuSvc = null;
$payuShops = [];
if ($payuOn) {
    try {
        \Pase\Services\PayuPayments::migrate($pdo);
        $payuSvc = new \Pase\Services\PayuPayments($pdo, new \Pase\Services\PayuPayouts($pdo));
        $payuSyncError = $payuSvc->sync($row);
        $payuPayments = $payuSvc->payments($wooOrderId);
        $payuRefunds = $payuSvc->refunds($wooOrderId);
        $payuLinks = $payuSvc->links($wooOrderId);
        foreach ((new \Pase\Services\PayuPayouts($pdo))->accounts() as $pa) { $payuShops[(int) $pa['id']] = (string) $pa['name']; }
    } catch (\Throwable $e) {
        $payuSyncError = $e->getMessage();
    }
    // Sekcja tylko, gdy zamówienie ma albo może mieć płatność PayU (metoda płatności PayU albo już przypisana)
    // albo nie jest opłacone - wtedy można wystawić link do płatności.
    $payuOn = $payuPayments !== [] || $payuLinks !== [] || str_starts_with(strtolower((string) ($o['payment_method'] ?? '')), 'payu')
        || ($payuShops !== [] && $paymentClass !== 'ok' && ($o['status'] ?? '') !== 'refunded' && empty($row['merged_into']));
}

// Zapisany układ sekcji tego użytkownika (drag & drop).
$savedLayout = null;
try {
    $ls = $pdo->prepare('SELECT layout FROM user_layouts WHERE user_id = ? AND view_key = ?');
    $ls->execute([currentUserId(), 'order_view']);
    $lj = $ls->fetchColumn();
    if ($lj) {
        $savedLayout = json_decode($lj, true) ?: null;
    }
} catch (\Throwable $e) {
    $savedLayout = null;
}

// Sekcje rozszerzeń (filtr order_view.sections): ['id' => 'notatki', 'title' => 'Notatki', 'col' => 'left'|'right',
// 'render' => fn(array $row, array $order): string]. Przeciąga się je i zapamiętuje jak sekcje rdzenia.
$extSections = ['left' => [], 'right' => []];
foreach (\Pase\Plugin\Hooks::applyFilters('order_view.sections', [], $row, $o) as $sec) {
    if (!is_array($sec) || !isset($sec['id'], $sec['render']) || !is_callable($sec['render'])) {
        continue;
    }
    $sec['id'] = 'x_' . preg_replace('/[^a-z0-9_]/', '', strtolower((string) $sec['id']));
    $extSections[($sec['col'] ?? 'left') === 'right' ? 'right' : 'left'][] = $sec;
}
$renderExtSections = static function (array $list) use ($row, $o): void {
    foreach ($list as $sec) {
        $html = \Pase\Plugin\Hooks::safe('order_view.sections:' . $sec['id'], $sec['render'], $row, $o);
        if (!is_string($html) || $html === '') {
            continue;
        }
        echo '<div class="card sortable-section" data-section="' . htmlspecialchars($sec['id']) . '">'
            . '<div class="drag-handle">⠿</div>'
            . (isset($sec['title']) ? '<strong>' . htmlspecialchars((string) $sec['title']) . '</strong>' : '')
            . '<div style="margin-top:10px">' . $html . '</div></div>';
    }
};

// Link do publicznej strony zamówienia klienta (token).
$clientLink = (new OrderMessageService($pdo, $msgRepo, new SettingsRepository($pdo)))->clientLink($row);

// Szablony odpowiedzi: aktywne szablony e-mail (Konfiguracja → Szablony e-mail) z podstawionymi
// danymi tego zamówienia, jako zwykły tekst do pola odpowiedzi (operator może go jeszcze poprawić).
$replyTemplates = [];
if (canEdit()) {
    try {
        $tplVars = (new \Pase\Automation\RuleEngine($pdo, new \Pase\Repository\AutomationRuleRepository($pdo)))
            ->templateVars($wooOrderId, array_merge($o, ['billing' => array_merge($billing, ['email' => $contactEmail])]));
        $mailAccounts = new \Pase\Services\MailAccounts($pdo);
        foreach ((new \Pase\Repository\EmailTemplateRepository($pdo))->active() as $t) {
            $vars = $tplVars;
            try {
                $cfg = $mailAccounts->mailerConfig($wooOrderId, (int) ($t['mail_account_id'] ?? 0));
                if (($cfg['from_name'] ?? '') !== '') {
                    $vars['shop_name'] = $cfg['from_name'];
                }
                if (($cfg['review_link'] ?? '') !== '') {
                    $vars['review_link'] = $cfg['review_link'];
                }
                if (($cfg['logo_url'] ?? '') !== '') {
                    $vars['shop_logo'] = \Pase\Services\MailAccounts::logoHtml($cfg);
                }
            } catch (\Throwable) {
                // brak kont pocztowych - zostaje nazwa sklepu z ustawień
            }
            $replyTemplates[] = [
                'id'   => (int) $t['id'],
                'name' => (string) $t['name'],
                'attach_invoice' => !empty($t['attach_invoice']),
                'body' => \Pase\Services\Mailer::htmlToText(\Pase\Services\Mailer::renderHtml((string) $t['body'], $vars)),
            ];
        }
    } catch (\Throwable) {
        $replyTemplates = []; // starsza baza bez tabeli szablonów - bez wyboru szablonu
    }
}

$PAGE_TITLE = 'Zamówienie ' . ($row['pase_number'] !== null ? '#' . (int)$row['pase_number'] : ('Woo ' . $wooOrderId));
$PAGE_KEY   = 'orders';
require __DIR__ . '/header.php';
?>

<div style="margin:-6px 0 16px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <a class="btn secondary" href="index.php">← Powrót do listy</a>
    <?php if (canEdit()): ?><a class="btn secondary" href="order_history.php?id=<?= (int) $wooOrderId ?>">Historia zmian</a><?php endif; ?>
    <?php if (usesPagePermissions() ? canViewPage('email_log') : roleCanEdit()): ?><a class="btn secondary" href="email_log.php?period=365d&amp;order=<?= (int) $wooOrderId ?>">Wysłane e-maile</a><?php endif; ?>
    <?php if ($adminLink): ?>
        <a class="btn secondary" href="<?= htmlspecialchars($adminLink) ?>" target="_blank" rel="noopener">Otwórz w WooCommerce ↗</a>
    <?php endif; ?>
    <?php if (canOpenPage('packing.php')): ?>
        <?php
            // Stan pakowania (asystent pakowania) - krótka informacja przy przycisku.
            $packInfo = null;
            try {
                \Pase\Services\Packing::migrate($pdo);
                $packInfo = (new \Pase\Services\Packing($pdo))->state($wooOrderId);
            } catch (\Throwable) {
            }
        ?>
        <a class="btn secondary" href="packing.php?ids=<?= (int) $wooOrderId ?>" title="Asystent pakowania">📦 Pakuj</a>
        <?php if ($packInfo !== null && $packInfo['state'] === 'packed'): ?>
            <span class="pill ok" title="<?= htmlspecialchars((string) $packInfo['packed_by']) ?>">✓ Spakowane<?= $packInfo['photos'] ? ' · 📷 ' . count($packInfo['photos']) : '' ?></span>
        <?php elseif ($packInfo !== null && $packInfo['state'] === 'problem'): ?>
            <span class="pill bad" title="<?= htmlspecialchars((string) $packInfo['note']) ?>">⚠ Nie spakowano</span>
        <?php elseif ($packInfo !== null && array_sum($packInfo['progress']) > 0): ?>
            <span class="pill warn">W trakcie pakowania</span>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Menu Drukuj/Eksportuj -->
    <div class="soon-menu" style="margin-left:auto">
        <button type="button" class="btn" onclick="this.closest('.soon-menu').classList.toggle('open')">🖨 Drukuj / Eksportuj ▾</button>
        <div class="soon-dropdown">
            <?php foreach ($printTemplates as $pt): $isZpl = $pt['format'] === 'ZPL'; ?>
                <?php // Nazwa = podgląd w nowej karcie. Dla ZPL dochodzi drugi przycisk:
                      // wysyłka prosto na Zebrę przez lokalnego agenta, bez otwierania pliku. ?>
                <div class="soon-row">
                    <a class="soon-item" style="flex:1;text-decoration:none" href="print_document.php?tpl=<?= (int) $pt['id'] ?>&order=<?= (int) $wooOrderId ?>" target="_blank" rel="noopener" title="<?= $isZpl ? 'Pobierz plik .zpl (ZPL to komendy drukarki, nie da się go podejrzeć w przeglądarce)' : 'Otwórz podgląd w nowej karcie' ?>">
                        <?= $isZpl ? '🏷️' : '📄' ?> <?= htmlspecialchars($pt['name']) ?>
                    </a>
                    <?php if ($isZpl): ?>
                        <a class="soon-agent doc-agent" href="#" data-tpl="<?= (int) $pt['id'] ?>" data-target="zebra" title="Drukuj od razu na Zebrze (przez agenta druku)">🖨➜ Zebra</a>
                    <?php else: ?>
                        <?php // Dokument A4/A5: agent renderuje go u siebie i drukuje na wskazanej drukarce. ?>
                        <a class="soon-agent doc-agent" href="#" data-tpl="<?= (int) $pt['id'] ?>" data-target="a4" title="Drukuj od razu na zwykłej drukarce (przez agenta druku)">🖨➜ A4</a>
                        <a class="soon-agent doc-agent" href="#" data-tpl="<?= (int) $pt['id'] ?>" data-target="zebra" title="Drukuj na drukarce etykiet — strona zostanie zmniejszona do szerokości taśmy">🖨➜ Zebra</a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if ($printTemplates === []): ?>
                <a class="soon-item" style="display:block;text-decoration:none;color:var(--ink-2)" href="print_templates.php">Brak szablonów — dodaj w Konfiguracja → Szablony wydruku</a>
            <?php endif; ?>
            <button class="soon-item" data-soon="Wystawianie faktury">📄 Faktura (PDF)</button>
            <button class="soon-item" data-soon="Eksport zamówienia">⬇ Eksport zamówienia</button>
        </div>
    </div>
</div>

<?php if ($flashOk): ?><div class="flash ok"><?= htmlspecialchars($flashOk) ?></div><?php endif; ?>
<?php if ($flashErr): ?><div class="flash err"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

<?php if ($mergedTarget !== null): ?>
<div class="merge-alert" role="status">
    <div style="flex:1;min-width:220px">
        <strong>Scalone z zamówieniem <a href="order_view.php?id=<?= (int) $mergedTarget['woo_order_id'] ?>"><?= htmlspecialchars(\Pase\Services\OrderMerge::label($mergedTarget)) ?></a></strong>
        <div>Produkty z tego zamówienia jadą w paczce zamówienia <?= htmlspecialchars(\Pase\Services\OrderMerge::label($mergedTarget)) ?> — nie nadawaj osobnej przesyłki. Status zmienia się razem z głównym. Faktura lub paragon jest wspólny, na zamówieniu głównym.</div>
    </div>
    <?php if (canEdit()): ?>
    <form method="post" action="order_merge.php" style="margin:0" onsubmit="return confirm('Odłączyć to zamówienie? Znowu będzie osobnym zamówieniem do wysłania.');">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="unmerge">
        <input type="hidden" name="source" value="<?= (int) $wooOrderId ?>">
        <input type="hidden" name="back" value="<?= (int) $wooOrderId ?>">
        <button class="btn secondary" type="submit">Odłącz</button>
    </form>
    <?php endif; ?>
</div>
<?php elseif ($absorbedOrders !== []): ?>
<div class="merge-alert" role="status">
    <div style="flex:1;min-width:220px">
        <strong>Zamówienie scalone</strong>
        <div>Dołączono: <?= implode(', ', array_map(static fn(array $ab): string => '<a href="order_view.php?id=' . (int) $ab['woo_order_id'] . '">' . htmlspecialchars(\Pase\Services\OrderMerge::label($ab)) . '</a>', $absorbedOrders)) ?>. Wysyłasz jedną paczkę z produktami wszystkich zamówień — razem <strong><?= htmlspecialchars(number_format($groupTotal, 2)) ?> <?= htmlspecialchars((string) ($row['currency'] ?? '')) ?></strong>.</div>
    </div>
</div>
<?php endif; ?>

<?php if ($invoiceNeeded):
    $invoiceNip = trim(\Pase\Services\InvoiceData::nip($o, isset($row['local_invoice']) ? (string) $row['local_invoice'] : null));
?>
<div class="invoice-alert" role="alert">
    <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 3h9l4 4v14l-3-2-3 2-3-2-4 2V3Z"/><path d="M14 3v5h5M9 11h7M9 15h7"/></svg>
    <div style="flex:1;min-width:220px">
        <strong>Trzeba wystawić fakturę</strong>
        <div><?= $invoiceIndicator['label'] === 'orders.indicator.invoice_required' ? 'Klient zaznaczył, że chce fakturę' : 'Klient podał NIP' ?><?= $invoiceNip !== '' ? ' (NIP ' . htmlspecialchars($invoiceNip) . ')' : '' ?>. Nie wystawiaj paragonu — to zamówienie idzie na fakturę.</div>
        <?php if ($wfirmaInteg !== null && canOpenPage('wfirma_receipts.php')): ?>
            <div><a href="wfirma_receipts.php?<?= htmlspecialchars(http_build_query(['check' => 1,
                'from' => substr((string) ($row['date_created'] ?? ''), 0, 10), 'to' => date('Y-m-d', strtotime(substr((string) ($row['date_created'] ?? 'now'), 0, 10) . ' +30 days'))])) ?>" style="color:inherit">Faktura wystawiona w BaseLinker? Powiąż z wFirma</a></div>
        <?php endif; ?>
    </div>
    <?php if (canEdit() && $wfirmaInteg !== null): ?>
        <form method="post" style="margin:0" data-document-confirm="Utworzyć fakturę w wFirma na podstawie bieżących danych zamówienia?" data-confirm-label="Wystaw fakturę">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="issue_wfirma_document">
            <input type="hidden" name="document_type" value="normal">
            <button class="btn" type="submit">Wystaw fakturę</button>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php
// Uwagi kupującego (Woo: customer_note, Allegro: messageToSeller) - także z zamówień dołączonych do tej paczki.
$customerNotes = [];
$ownNote = \Pase\Services\OrderIndicators::customerNote($o);
if ($ownNote !== '') {
    $customerNotes[] = ['from' => null, 'text' => $ownNote];
}
foreach ($absorbedOrders as $ab) {
    $abNote = \Pase\Services\OrderIndicators::customerNote(json_decode((string) ($ab['payload'] ?? ''), true) ?: []);
    if ($abNote !== '') {
        $customerNotes[] = ['from' => $ab, 'text' => $abNote];
    }
}
?>
<?php if ($customerNotes !== []): ?>
<div class="note-alert" id="customer-note" role="note">
    <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= \Pase\Services\OrderIconDetails::ICON_PATHS['note'] ?></svg>
    <div style="flex:1;min-width:220px">
        <strong>Uwaga od klienta</strong>
        <?php foreach ($customerNotes as $cn): ?>
            <?php if ($cn['from'] !== null): ?>
                <div class="note-from">Z dołączonego zamówienia <a href="order_view.php?id=<?= (int) $cn['from']['woo_order_id'] ?>"><?= htmlspecialchars(\Pase\Services\OrderMerge::label($cn['from'])) ?></a>:</div>
            <?php endif; ?>
            <div class="note-text"><?= nl2br(htmlspecialchars($cn['text'])) ?></div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (canEdit()): ?>
<p style="color:#888;font-size:12px;margin:0 0 10px">💡 Sekcje możesz przeciągać za uchwyt <strong>⠿</strong> i układać po swojemu — układ zapamiętuje się dla Twojego konta.</p>
<?php endif; ?>

<div class="layout-cols">
<div class="layout-col" data-col="left">

<!-- ===== Status (zmiana) ===== -->
<div class="card sortable-section" data-section="status">
    <div class="drag-handle">⠿</div>
    <strong>Status zamówienia</strong>
    <div style="display:flex;align-items:center;gap:14px;margin-top:10px">
        <?php
            $cur = $statusMap[$row['pase_status'] ?? ''] ?? null;
            $curColor = $cur['color'] ?? '#888888';
            $curLabel = $cur['label'] ?? ($row['pase_status'] ?: '—');
        ?>
        <span style="display:inline-block;padding:4px 12px;border-radius:999px;font-size:14px;font-weight:600;color:#fff;background:<?= htmlspecialchars($curColor) ?>"><?= htmlspecialchars($curLabel) ?></span>
        <?php if (canEdit()): ?>
        <form method="post" style="display:flex;gap:8px;align-items:center;margin:0">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="set_status">
            <select name="pase_status">
                <?php foreach ($statusList as $s): ?>
                    <option value="<?= htmlspecialchars($s['status_key']) ?>" <?= ($row['pase_status'] ?? '') === $s['status_key'] ? 'selected' : '' ?>><?= htmlspecialchars($s['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn" type="submit">Zmień status</button>
        </form>
        <?php endif; ?>
    </div>
    <?php if ($syncEnabled): ?>
        <p style="color:#888;font-size:12px;margin:8px 0 0">Synchronizacja do <?= $sourceType === 'allegro' ? 'Allegro' : 'WooCommerce' ?>: <strong>włączona</strong> — zmiana zaktualizuje też status <?= $sourceType === 'allegro' ? 'na Allegro' : 'w sklepie' ?>.</p>
    <?php endif; ?>
</div>

<!-- ===== Dokumenty ===== -->
<div class="card sortable-section" data-section="documents">
    <div class="drag-handle">⠿</div>
    <strong>Dokumenty</strong>
    <?php
    // Faktura: wystawiona -> zielony znacznik z linkiem do wFirma; wymagana, a nie ma -> pomarańczowy.
    $invoiceDoc = null;
    foreach ($orderDocuments as $document) {
        if ($document['provider'] === 'wfirma' && ($document['status'] ?? '') === 'issued' && $document['document_type'] === 'normal') { $invoiceDoc = $document; break; }
    }
    ?>
    <?php if ($invoiceDoc !== null): ?>
        <a class="receipt-state ok" href="https://wfirma.pl/invoices/view/<?= htmlspecialchars(rawurlencode((string) $invoiceDoc['remote_id'])) ?>" target="_blank" rel="noopener noreferrer" title="Faktura wystawiona — otwórz w wFirma">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 3h9l4 4v14l-3-2-3 2-3-2-4 2V3Z"/><path d="M14 3v5h5M9 11h7M9 15h7"/></svg>
            Faktura wystawiona — otwórz w wFirma ↗
        </a>
    <?php elseif ($invoiceNeeded): ?>
        <span class="receipt-state warn" title="<?= htmlspecialchars(t($invoiceIndicator['label'])) ?>">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 3h9l4 4v14l-3-2-3 2-3-2-4 2V3Z"/><path d="M14 3v5h5M9 11h7M9 15h7"/></svg>
            Brak faktury — do wystawienia
        </span>
    <?php endif; ?>
    <?php if ($receiptIndicator['state'] !== 'muted'):
        // Powiązany paragon (z CRM albo z BaseLinkera) - znacznik otwiera go w wFirma.
        $receiptDoc = null;
        foreach ($orderDocuments as $document) {
            if ($document['provider'] === 'wfirma' && ($document['status'] ?? '') === 'issued'
                && in_array($document['document_type'], ['receipt', 'receipt_fiscal'], true)) { $receiptDoc = $document; break; }
        }
        $receiptTag = $receiptDoc !== null ? 'a' : 'span';
    ?>
        <<?= $receiptTag ?> class="receipt-state <?= htmlspecialchars($receiptIndicator['state']) ?>"
            <?php if ($receiptDoc !== null): ?>href="https://wfirma.pl/invoices/view/<?= htmlspecialchars(rawurlencode((string) $receiptDoc['remote_id'])) ?>" target="_blank" rel="noopener noreferrer"<?php endif; ?>
            title="<?= htmlspecialchars(t($receiptIndicator['label'])) ?><?= $receiptDoc !== null ? ' — otwórz w wFirma' : '' ?>">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 3l2 2 2-2 3 2 3-2 2 2 2-2v18l-2-2-2 2-3-2-3 2-2-2-2 2V3Z"/><path d="M8 9h8M8 13h8M8 17h4"/></svg>
            <?= $receiptIndicator['state'] === 'ok' ? 'Paragon wystawiony' . ($receiptDoc !== null ? ' — otwórz w wFirma ↗' : '') : 'Brak paragonu — do wystawienia' ?>
        </<?= $receiptTag ?>>
        <?php if ($receiptIndicator['state'] === 'warn' && $wfirmaInteg !== null && canOpenPage('wfirma_receipts.php')): ?>
            <a class="receipt-link" href="wfirma_receipts.php?<?= htmlspecialchars(http_build_query(['check' => 1,
                'from' => substr((string) ($row['date_created'] ?? ''), 0, 10), 'to' => date('Y-m-d', strtotime(substr((string) ($row['date_created'] ?? 'now'), 0, 10) . ' +30 days'))])) ?>">Paragon lub faktura wystawione w BaseLinker? Powiąż z wFirma</a>
        <?php endif; ?>
    <?php endif; ?>
    <?php
    $documentsByType = [];
    foreach ($orderDocuments as $document) {
        if ($document['provider'] === 'wfirma') {
            $documentsByType[$document['document_type']][] = $document;
        }
    }
    ?>
    <?php if ($docsFromMain !== null): ?>
        <p style="color:#888;font-size:13px;margin:12px 0 0">To zamówienie jest scalone — faktura lub paragon obejmuje całą paczkę i jest w <a href="order_view.php?id=<?= (int) $docsFromMain ?>" style="color:inherit;text-decoration:underline;text-underline-offset:3px">zamówieniu głównym</a>.</p>
    <?php else: ?>
    <?php
    // Podświetlony dokument, którego chce klient: faktura, gdy prosi o nią (albo podał NIP),
    // inaczej paragon - już wystawiony, a gdy go nie ma, niefiskalny (jak w automatyzacji).
    $primaryDocType = $invoiceIndicator['state'] === 'warn' ? 'normal'
        : (!empty($documentsByType['receipt_fiscal']) && empty($documentsByType['receipt']) ? 'receipt_fiscal' : 'receipt');
    ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start;margin-top:14px">
        <?php foreach ([
            'normal' => 'Wystaw fakturę',
            'receipt' => 'Paragon niefiskalny',
            'receipt_fiscal' => 'Paragon fiskalny',
            'proforma' => 'Proforma',
        ] as $docType => $buttonLabel):
            $buttonClass = $docType === $primaryDocType ? 'btn' : 'btn secondary'; ?>
            <?php if (!empty($documentsByType[$docType])): ?>
                <details style="max-width:100%">
                    <summary class="<?= $buttonClass ?>" style="cursor:pointer;list-style:none">✓ <?= $buttonLabel ?> ▾</summary>
                    <div style="padding:14px;margin-top:8px;border:1px solid #e5e7eb;border-radius:10px;overflow-wrap:anywhere">
                        <?php foreach ($documentsByType[$docType] as $document):
                            $documentUrl = 'https://wfirma.pl/invoices/view/' . rawurlencode((string) $document['remote_id']);
                        ?>
                            <p style="margin:0 0 6px"><strong>Powiązany dokument w wFirma</strong></p>
                            <p style="margin:0 0 6px">ID: <?= htmlspecialchars($document['remote_id']) ?></p>
                            <p style="margin:0 0 12px">Data: <?= htmlspecialchars($document['created_at']) ?></p>
                            <a class="btn secondary" href="<?= htmlspecialchars($documentUrl, ENT_QUOTES) ?>" target="_blank" rel="noopener noreferrer">Otwórz w wFirma ↗</a>
                            <?php if (canEdit() && in_array($docType, ['receipt', 'receipt_fiscal'], true)): ?>
                                <form method="post" style="margin:8px 0 0" data-document-confirm="Usunąć lokalne powiązanie paragonu i odblokować ponowne wystawienie? Ta operacja nie usuwa dokumentu z wFirma." data-confirm-label="Usuń powiązanie" data-confirm-danger="true">
                                    <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="action" value="unlink_wfirma_receipt">
                                    <input type="hidden" name="document_id" value="<?= (int) $document['id'] ?>">
                                    <button type="submit" class="btn secondary">Usuń powiązanie</button>
                                </form>
                                <form method="post" style="margin:8px 0 0" data-document-confirm="Usunąć ten paragon również z wFirma? Dokument zostanie usunięty z wFirma, a po potwierdzeniu także jego lokalne powiązanie." data-confirm-label="Usuń również z wFirma" data-confirm-danger="true">
                                    <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="action" value="delete_wfirma_receipt">
                                    <input type="hidden" name="document_id" value="<?= (int) $document['id'] ?>">
                                    <button type="submit" class="btn secondary" style="color:#b42318">Usuń również z wFirma</button>
                                </form>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php elseif (canEdit() && $wfirmaInteg !== null): ?>
                <form method="post" style="margin:0" data-document-confirm="Utworzyć dokument w wFirma na podstawie bieżących danych zamówienia?" data-confirm-label="Wystaw dokument">
                    <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="issue_wfirma_document">
                    <input type="hidden" name="document_type" value="<?= $docType ?>">
                    <button class="<?= $buttonClass ?>" type="submit"><?= $buttonLabel ?></button>
                </form>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php if ($orderDocuments === []): ?>
        <p style="color:#888;font-size:13px">Brak wystawionych dokumentów.</p>
    <?php else: ?>
        <p style="color:#888;font-size:12px">Kliknij dokument oznaczony ✓, aby zobaczyć powiązanie i dostępne akcje.</p>
    <?php endif; ?>
    <?php endif; /* dokumenty w zamówieniu głównym */ ?>
    <?php if ($wfirmaInteg === null): ?>
        <p style="color:#b06000;font-size:12px">Brak aktywnej integracji wFirma. Dodaj ją lub włącz w Konfiguracja → Integracje.</p>
    <?php endif; ?>
</div>

<!-- ===== Zwroty ===== -->
<div class="card sortable-section" data-section="returns">
    <div class="drag-handle">⠿</div>
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding-right:30px">
        <strong>Zwroty</strong>
        <?php if (canEdit() && canOpenPage('returns.php')): ?><a class="btn secondary" href="returns.php?new=<?= (int) $wooOrderId ?>">↩ Zgłoś zwrot</a><?php endif; ?>
    </div>
    <?php if ($orderReturns === []): ?>
        <p style="color:#888;font-size:13px">Brak zwrotów.</p>
    <?php else: foreach ($orderReturns as $orderReturn):
        $retSteps = [
            'na stanie' => $orderReturn['restocked_at'] !== null,
            'korekta' => $orderReturn['correction_state'] === 'done',
            'pieniądze zwrócone' => $orderReturn['refund_state'] === 'done',
        ];
    ?>
        <div style="margin-top:10px;padding:10px 12px;border:1px solid #f0d9a8;background:#fff8ec;border-radius:8px">
            <a href="returns.php?id=<?= (int) $orderReturn['id'] ?>"><strong>↩ Klient zwrócił towar</strong></a>
            · <?= htmlspecialchars(\Pase\Services\OrderReturns::statusLabel((string) $orderReturn['status'])) ?>
            · <?= htmlspecialchars(implode(', ', array_map(static fn($it) => $it['quantity'] . '× ' . $it['name'], $orderReturn['items']))) ?>
            <div style="margin-top:6px;font-size:13px">
                <?php foreach ($retSteps as $retLabel => $retDone): ?>
                    <span style="margin-right:10px;color:<?= $retDone ? '#1c6b36' : '#8a5a00' ?>"><?= $retDone ? '✓' : '○' ?> <?= htmlspecialchars($retLabel) ?></span>
                <?php endforeach; ?>
                <?php if (in_array('uncertain', [$orderReturn['correction_state'], $orderReturn['refund_state']], true)): ?><span style="color:#a3341f">⚠ wynik do sprawdzenia</span><?php endif; ?>
            </div>
        </div>
    <?php endforeach; endif; ?>
    <?php foreach ($orderFeedback['issues'] as $orderIssue): $issueOpen = \Pase\Services\AllegroFeedback::isOpen($orderIssue); ?>
        <div style="margin-top:10px;padding:10px 12px;border:1px solid <?= $issueOpen ? '#f3c2bc' : '#e3e6ea' ?>;background:<?= $issueOpen ? '#fff7f6' : '#fafbfc' ?>;border-radius:8px">
            <a href="allegro_messages.php?tab=issues&amp;issue=<?= urlencode((string) $orderIssue['remote_id']) ?>"><strong>💬 <?= htmlspecialchars(\Pase\Services\AllegroFeedback::typeLabel((string) $orderIssue['type'])) ?> na Allegro</strong></a>
            · <?= htmlspecialchars(\Pase\Services\AllegroFeedback::statusLabel((string) $orderIssue['status'])) ?>
            <?php if (\Pase\Services\AllegroFeedback::awaitsSeller($orderIssue)): ?><span style="color:#a3341f"> · czeka na odpowiedź</span><?php endif; ?>
            <?php if ($orderIssue['subject']): ?><div style="font-size:13px;color:#555;margin-top:4px"><?= htmlspecialchars((string) $orderIssue['subject']) ?></div><?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php foreach ($orderFeedback['ratings'] as $orderRating): $ratingNeg = (int) $orderRating['recommended'] === 0; ?>
        <div style="margin-top:10px;padding:10px 12px;border:1px solid <?= $ratingNeg ? '#f3c2bc' : '#cfe8d6' ?>;background:<?= $ratingNeg ? '#fff7f6' : '#f5fbf7' ?>;border-radius:8px">
            <a href="allegro_messages.php?tab=ratings&amp;f=#r-<?= htmlspecialchars((string) $orderRating['remote_id']) ?>"><strong><?= $ratingNeg ? '👎 Negatywna ocena' : '👍 Pozytywna ocena' ?> na Allegro</strong></a>
            <?php if ($ratingNeg && !$orderRating['answer_text']): ?><span style="color:#a3341f"> · bez odpowiedzi</span><?php endif; ?>
            <?php if ($orderRating['comment']): ?><div style="font-size:13px;color:#555;margin-top:4px">„<?= htmlspecialchars((string) $orderRating['comment']) ?>”</div><?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<?php if ($payuOn): $pm = static fn(int $g, string $c): string => \Pase\Services\PayuPayouts::money($g, $c); ?>
<!-- ===== Płatność PayU ===== -->
<div class="card sortable-section" data-section="payu">
    <div class="drag-handle">⠿</div>
    <div style="padding-right:30px"><strong>Płatność PayU</strong></div>
    <?php if ($payuSyncError !== null): ?><p style="color:#a3341f;font-size:13px">PayU: <?= htmlspecialchars($payuSyncError) ?></p><?php endif; ?>
    <?php if ($payuPayments === [] && str_starts_with(strtolower((string) ($o['payment_method'] ?? '')), 'payu') && ($o['payment_method'] ?? '') !== 'payu_link'): ?>
        <p style="color:#888;font-size:13px">Brak przypisanej płatności PayU.<?= \Pase\Services\PayuPayments::candidateId($o) === null ? ' Sklep nie podał numeru płatności PayU - możesz go wpisać (panel PayU → Transakcje → numer zamówienia, np. VVLR1HXK2S160929GUEST000P01).' : '' ?></p>
    <?php endif; ?>
    <?php foreach ($payuPayments as $pp): $ppRefundable = $payuSvc->refundable($pp); $ppCur = (string) ($pp['currency'] ?: 'PLN'); ?>
        <table style="margin-top:8px;font-size:13px">
            <tr><th style="width:38%">Numer w PayU</th><td style="font-family:var(--font-num)"><?= htmlspecialchars((string) $pp['payu_order_id']) ?></td></tr>
            <tr><th>Status</th><td><span class="pill <?= $pp['status'] === 'COMPLETED' ? 'ok' : ($pp['status'] === 'CANCELED' ? 'bad' : 'warn') ?>"><?= htmlspecialchars(\Pase\Services\PayuPayments::statusLabel((string) $pp['status'])) ?></span></td></tr>
            <tr><th>Kwota</th><td><?= htmlspecialchars($pm((int) $pp['amount'], $ppCur)) ?><?php if ($ppRefundable < (int) $pp['amount'] && $pp['status'] === 'COMPLETED'): ?> <span style="color:#888">(do zwrotu zostało <?= htmlspecialchars($pm($ppRefundable, $ppCur)) ?>)</span><?php endif; ?></td></tr>
            <?php if ((string) $pp['pay_method'] !== ''): ?><tr><th>Metoda</th><td><?= htmlspecialchars((string) $pp['pay_method']) ?></td></tr><?php endif; ?>
            <?php if ((string) $pp['paid_at'] !== ''): ?><tr><th>Utworzona</th><td><?= htmlspecialchars(date('d.m.Y H:i', strtotime((string) $pp['paid_at']) ?: time())) ?></td></tr><?php endif; ?>
            <?php if ((string) $pp['buyer'] !== ''): ?><tr><th>Płacący</th><td><?= htmlspecialchars((string) $pp['buyer']) ?></td></tr><?php endif; ?>
            <tr><th>Sklep PayU</th><td><?= htmlspecialchars($payuShops[(int) $pp['integration_id']] ?? '—') ?>
                <span style="color:#888"> · <?= $pp['linked_by'] === 'auto' ? 'przypisana automatycznie' : (str_starts_with((string) $pp['linked_by'], 'link: ') ? 'z linku do płatności, utworzył(a) ' . htmlspecialchars(substr((string) $pp['linked_by'], 6)) : 'przypisał(a) ' . htmlspecialchars((string) $pp['linked_by'])) ?></span></td></tr>
        </table>
        <details class="payu-details" style="margin-top:8px">
            <summary style="cursor:pointer;font-size:13px">Szczegóły płatności<?= canEdit() ? ' i zwrot' : '' ?></summary>
            <?php foreach ($payuSvc->details($pp, $payuShops[(int) $pp['integration_id']] ?? '') as $ppSec => $ppRows): ?>
                <div style="margin-top:10px;font-size:12px;font-weight:600;color:#666;text-transform:uppercase;letter-spacing:.03em"><?= htmlspecialchars($ppSec) ?></div>
                <table style="font-size:13px;margin-top:4px">
                    <?php foreach ($ppRows as $ppLabel => $ppVal): ?>
                        <tr><th style="width:38%"><?= htmlspecialchars($ppLabel) ?></th><td<?= in_array($ppLabel, ['ID transakcji', 'Numer zamówienia w PayU', 'Numer konta'], true) ? ' style="font-family:var(--font-num)"' : '' ?>><?= htmlspecialchars($ppVal) ?></td></tr>
                    <?php endforeach; ?>
                </table>
            <?php endforeach; ?>
            <?php if (canEdit()): ?>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;padding-top:10px;border-top:1px solid #eee">
                <?php if ($ppRefundable > 0): ?><a class="btn" href="payu_refund.php?order=<?= (int) $wooOrderId ?>&payment=<?= (int) $pp['id'] ?>">↩ Zwróć pieniądze przez PayU…</a><?php endif; ?>
                <form method="post" onsubmit="return confirm('Odłączyć płatność PayU od zamówienia? W PayU nic się nie zmieni.');"><input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="payu_unlink"><input type="hidden" name="payment_id" value="<?= (int) $pp['id'] ?>">
                    <button class="btn secondary" type="submit">Odłącz od zamówienia</button></form>
            </div>
            <?php endif; ?>
        </details>
        <?php if (canEdit()): ?>
        <form method="post" style="margin-top:8px"><input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="payu_refresh"><input type="hidden" name="payment_id" value="<?= (int) $pp['id'] ?>">
            <button class="btn secondary" type="submit" style="font-size:12px;padding:5px 9px">⟳ Odśwież z PayU</button></form>
        <?php endif; ?>
    <?php endforeach; ?>
    <?php if ($payuLinks !== []): ?>
        <div style="margin-top:10px"><strong style="font-size:13px">Linki do płatności</strong></div>
        <?php foreach ($payuLinks as $pk):
            $pkPay = (string) ($pk['payment_status'] ?? '');
            $pkExpired = $pk['expires_at'] !== null && strtotime((string) $pk['expires_at']) < time();
            [$pkLabel, $pkTone] = match (true) {
                $pk['status'] === 'SENDING' => ['wysyłanie do PayU', 'warn'],
                $pk['status'] === 'UNKNOWN' => ['nie wiadomo - sprawdź w panelu PayU', 'bad'],
                $pk['status'] === 'ERROR' => ['nie utworzono', 'bad'],
                $pkPay === 'COMPLETED' => ['opłacony', 'ok'],
                $pkPay === 'CANCELED' => ['anulowany / wygasł', 'bad'],
                $pkPay === 'PENDING' || $pkPay === 'WAITING_FOR_CONFIRMATION' => ['klient płaci', 'warn'],
                $pkExpired => ['wygasł', 'bad'],
                default => ['czeka na zapłatę', 'warn'],
            };
            $pkUsable = $pk['status'] === 'CREATED' && (string) $pk['link'] !== '' && !$pkExpired && !in_array($pkPay, ['COMPLETED', 'CANCELED'], true); ?>
            <div style="margin-top:8px;padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px">
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                    <strong style="font-family:var(--font-num)"><?= htmlspecialchars($pm((int) $pk['amount'], (string) $pk['currency'])) ?></strong>
                    <span class="pill <?= $pkTone ?>"><?= htmlspecialchars($pkLabel) ?></span>
                    <span style="color:#888"><?= htmlspecialchars($payuShops[(int) $pk['integration_id']] ?? '') ?> · <?= htmlspecialchars((string) $pk['created_by']) ?> · <?= htmlspecialchars(date('d.m.Y H:i', strtotime((string) $pk['created_at']))) ?><?php if ($pk['expires_at'] !== null && $pkPay !== 'COMPLETED'): ?> · ważny do <?= htmlspecialchars(date('d.m.Y H:i', strtotime((string) $pk['expires_at']))) ?><?php endif; ?></span>
                </div>
                <?php if ($pkUsable): ?>
                <div style="display:flex;gap:6px;margin-top:6px">
                    <input value="<?= htmlspecialchars((string) $pk['link']) ?>" readonly onclick="this.select()" style="flex:1;min-width:0;font-size:12px" aria-label="Link do płatności">
                    <button type="button" class="btn secondary payu-copy" data-link="<?= htmlspecialchars((string) $pk['link']) ?>" style="font-size:12px;padding:5px 9px">Kopiuj</button>
                </div>
                <?php endif; ?>
                <?php if ((string) $pk['error'] !== ''): ?><div style="color:#a3341f;margin-top:4px"><?= htmlspecialchars((string) $pk['error']) ?></div><?php endif; ?>
            </div>
        <?php endforeach; ?>
        <script>document.querySelectorAll('.payu-copy').forEach((b) => b.addEventListener('click', () => {
            const done = () => { const t = b.textContent; b.textContent = 'Skopiowano ✓'; setTimeout(() => { b.textContent = t; }, 1600); };
            if (navigator.clipboard) { navigator.clipboard.writeText(b.dataset.link).then(done, () => { b.previousElementSibling.select(); document.execCommand('copy'); done(); }); }
            else { b.previousElementSibling.select(); document.execCommand('copy'); done(); }
        }));</script>
    <?php endif; ?>
    <?php if (canEdit() && $payuShops !== [] && $mergedTarget === null):
        $linkDue = max(0.0, $groupTotal - ($manualPaid !== null ? $manualPaid['amount'] : ($paymentClass === 'ok' ? $groupTotal : 0.0)));
        $linkOpen = $payuPayments === [] && $payuLinks === [] && $paymentClass !== 'ok'; ?>
        <details style="margin-top:10px"<?= $linkOpen ? ' open' : '' ?>><summary style="cursor:pointer;font-size:13px">Utwórz link do płatności PayU</summary>
            <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-top:8px">
                <input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="payu_create_link">
                <input type="hidden" name="link_ext" value="<?= htmlspecialchars(\Pase\Services\PayuPayments::newLinkExtId($wooOrderId)) ?>">
                <div><label style="font-size:11px;color:var(--ink-2);display:block">Kwota (PLN)</label>
                    <input name="link_amount" value="<?= htmlspecialchars(number_format($linkDue > 0 ? $linkDue : $groupTotal, 2, '.', '')) ?>" inputmode="decimal" required style="width:100px;font-family:var(--font-num)"></div>
                <?php if (count($payuShops) > 1): ?>
                <div><label style="font-size:11px;color:var(--ink-2);display:block">Sklep PayU</label>
                    <select name="link_shop"><?php foreach ($payuShops as $sid => $sname): ?><option value="<?= (int) $sid ?>"><?= htmlspecialchars($sname) ?></option><?php endforeach; ?></select></div>
                <?php else: ?><input type="hidden" name="link_shop" value="<?= (int) array_key_first($payuShops) ?>"><?php endif; ?>
                <div><label style="font-size:11px;color:var(--ink-2);display:block">Ważny</label>
                    <select name="link_days"><?php foreach ([1 => '1 dzień', 3 => '3 dni', 7 => '7 dni', 14 => '14 dni', 30 => '30 dni'] as $d => $dl): ?><option value="<?= $d ?>"<?= $d === 7 ? ' selected' : '' ?>><?= $dl ?></option><?php endforeach; ?></select></div>
                <div style="flex:1;min-width:180px"><label style="font-size:11px;color:var(--ink-2);display:block">Tytuł płatności</label>
                    <input name="link_description" value="<?= htmlspecialchars('Zamówienie #' . ($row['pase_number'] ?? $wooOrderId)) ?>" maxlength="200" style="width:100%;box-sizing:border-box"></div>
                <button class="btn" type="submit">Utwórz link</button>
            </form>
            <p style="color:#888;font-size:12px;margin:6px 0 0">Link pokaże się tutaj do skopiowania - CRM nie wysyła go klientowi. Gdy klient zapłaci, płatność i wpłata zapiszą się przy tym zamówieniu.</p>
        </details>
    <?php endif; ?>
    <?php if ($payuRefunds !== []): ?>
        <div style="margin-top:12px"><strong style="font-size:13px">Zwroty PayU</strong></div>
        <table style="font-size:13px">
            <?php foreach ($payuRefunds as $pr): $prS = (string) $pr['status']; ?>
                <tr><td><?= htmlspecialchars(date('d.m.Y H:i', strtotime((string) $pr['created_at']))) ?></td>
                    <td style="white-space:nowrap"><?= htmlspecialchars($pm((int) $pr['amount'], (string) $pr['currency'])) ?></td>
                    <td><span class="pill <?= $prS === 'FINALIZED' ? 'ok' : (in_array($prS, ['CANCELED', 'ERROR', 'UNKNOWN'], true) ? 'bad' : 'warn') ?>"><?= htmlspecialchars(\Pase\Services\PayuPayments::refundLabel($prS)) ?></span>
                        <?php if ((string) $pr['error'] !== ''): ?><div style="color:#888"><?= htmlspecialchars((string) $pr['error']) ?></div><?php endif; ?></td>
                    <td style="color:#888"><?= htmlspecialchars((string) $pr['description']) ?> · <?= htmlspecialchars((string) $pr['created_by']) ?></td></tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
    <?php if (canEdit()): ?>
        <details style="margin-top:10px"<?= $payuPayments === [] && str_starts_with(strtolower((string) ($o['payment_method'] ?? '')), 'payu') && ($o['payment_method'] ?? '') !== 'payu_link' ? ' open' : '' ?>><summary style="cursor:pointer;font-size:13px"><?= $payuPayments === [] ? 'Przypisz płatność PayU' : 'Przypisz kolejną płatność PayU' ?></summary>
            <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
                <input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="payu_link">
                <input name="payu_order_id" placeholder="numer zamówienia w PayU" required style="min-width:260px;font-family:var(--font-num)">
                <button class="btn secondary" type="submit">Przypisz</button>
            </form>
        </details>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ===== Produkty ===== -->
<div class="card sortable-section" data-section="products">
    <div class="drag-handle">⠿</div>
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding-right:30px">
        <strong>Produkty<?php if ($isLocallyEdited): ?> <span class="pill warn" style="font-size:11px;margin-left:4px">edytowane lokalnie</span><?php endif; ?></strong>
        <?php if (canEdit()): ?>
            <div style="display:flex;align-items:center;gap:8px">
                <?php if ($isLocallyEdited): ?>
                    <form method="post" onsubmit="return confirm('Przywrócić oryginalne pozycje ze sklepu? Lokalne zmiany zostaną utracone.');">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="revert_items">
                        <button type="submit" class="btn secondary" style="font-size:12px;padding:6px 10px">Przywróć oryginał</button>
                    </form>
                <?php endif; ?>
                <button type="button" class="btn secondary" style="display:inline-flex;padding:7px;line-height:0" title="Edytuj produkty" aria-label="Edytuj produkty" onclick="var e=document.getElementById('itemsEditor');e.hidden=!e.hidden;">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
                </button>
            </div>
        <?php endif; ?>
    </div>
    <?php if ($isLocallyEdited): ?>
        <p style="color:#888;font-size:12px;margin:6px 0 0">Zmiany zapisane w CRM — faktura i paragon wystawione z CRM (wFirma) oraz wydruki biorą te pozycje. Do sklepu i Allegro nie są wysyłane.</p>
    <?php endif; ?>

    <table style="margin-top:10px">
        <tr><th></th><th>ID</th><th>Nazwa produktu</th><th>SKU</th><th>Ilość</th><th>Cena</th><th>Suma</th></tr>
        <?php foreach ($items as $itIdx => $it): $thumb = $itemThumbs[$itIdx] ?? null; ?>
            <tr>
                <td style="width:56px">
                    <?php if ($thumb): ?>
                        <a href="<?= htmlspecialchars($thumb) ?>" class="item-thumb" data-caption="<?= htmlspecialchars((string) ($it['name'] ?? '')) ?>" target="_blank" rel="noopener"><img src="<?= htmlspecialchars($thumb) ?>" alt="" loading="lazy" style="width:48px;height:48px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb;display:block"></a>
                    <?php else: ?>
                        <span style="display:flex;align-items:center;justify-content:center;width:48px;height:48px;border-radius:6px;background:#f1f3f4;color:#bbb;font-size:16px">—</span>
                    <?php endif; ?>
                </td>
                <td><?= (int) ($it['variation_id'] ?? 0) ?: ((int) ($it['product_id'] ?? 0) ?: '—') ?></td>
                <td><strong><?= $g($it, 'name') ?></strong>
                    <?php if (($it['in_warehouse'] ?? true) === false): ?>
                        <br><span style="color:#a3341f;font-size:12px">spoza magazynu CRM<?= !empty($it['allegro_offer_id']) ? ' · oferta Allegro ' . htmlspecialchars((string) $it['allegro_offer_id']) : '' ?></span>
                    <?php endif; ?>
                </td>
                <td><?= $g($it, 'sku', '—') ?></td>
                <td><?= (int)($it['quantity'] ?? 0) ?></td>
                <td><?= htmlspecialchars(number_format((float)($it['price'] ?? 0), 2)) ?> <?= htmlspecialchars($currency) ?></td>
                <td><?= htmlspecialchars(number_format((float)($it['total'] ?? 0), 2)) ?> <?= htmlspecialchars($currency) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($items === []): ?><tr><td colspan="7" style="color:#888">Brak pozycji.</td></tr><?php endif; ?>
        <?php foreach ($absorbedOrders as $ab): $abId = (int) $ab['woo_order_id']; ?>
            <tr class="merged-head"><td colspan="7">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
                    <span>Z zamówienia <a href="order_view.php?id=<?= $abId ?>"><strong><?= htmlspecialchars(\Pase\Services\OrderMerge::label($ab)) ?></strong></a> · <?= htmlspecialchars(number_format(\Pase\Services\OrderMerge::orderTotal($ab), 2)) ?> <?= htmlspecialchars((string) ($ab['currency'] ?: $currency)) ?></span>
                    <?php if (canEdit()): ?>
                    <form method="post" action="order_merge.php" style="margin:0" onsubmit="return confirm('Odłączyć to zamówienie? Znowu będzie osobnym zamówieniem do wysłania.');">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="unmerge">
                        <input type="hidden" name="source" value="<?= $abId ?>">
                        <input type="hidden" name="back" value="<?= (int) $wooOrderId ?>">
                        <button class="btn secondary" type="submit" style="font-size:12px;padding:5px 9px">Odłącz</button>
                    </form>
                    <?php endif; ?>
                </div>
            </td></tr>
            <?php foreach ($mergedExtraItems as $mIdx => $it): if ((int) $it['merged_from'] !== $abId) { continue; } $thumb = $mergedThumbs[$mIdx] ?? null; ?>
            <tr class="merged-item">
                <td style="width:56px">
                    <?php if ($thumb): ?>
                        <a href="<?= htmlspecialchars($thumb) ?>" class="item-thumb" data-caption="<?= htmlspecialchars((string) ($it['name'] ?? '')) ?>" target="_blank" rel="noopener"><img src="<?= htmlspecialchars($thumb) ?>" alt="" loading="lazy" style="width:48px;height:48px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb;display:block"></a>
                    <?php else: ?>
                        <span style="display:flex;align-items:center;justify-content:center;width:48px;height:48px;border-radius:6px;background:#f1f3f4;color:#bbb;font-size:16px">—</span>
                    <?php endif; ?>
                </td>
                <td><?= (int) ($it['variation_id'] ?? 0) ?: ((int) ($it['product_id'] ?? 0) ?: '—') ?></td>
                <td><strong><?= $g($it, 'name') ?></strong></td>
                <td><?= $g($it, 'sku', '—') ?></td>
                <td><?= (int)($it['quantity'] ?? 0) ?></td>
                <td><?= htmlspecialchars(number_format((float)($it['price'] ?? 0), 2)) ?> <?= htmlspecialchars($currency) ?></td>
                <td><?= htmlspecialchars(number_format((float)($it['total'] ?? 0), 2)) ?> <?= htmlspecialchars($currency) ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        <?php if ($absorbedOrders !== []): ?>
            <tr class="merged-head"><td colspan="6" style="text-align:right"><strong>Razem paczka (scalone zamówienia)</strong></td><td><strong><?= htmlspecialchars(number_format($groupTotal, 2)) ?> <?= htmlspecialchars($currency) ?></strong></td></tr>
        <?php endif; ?>
    </table>
    <?php if ($absorbedOrders !== []): ?>
        <p style="color:#888;font-size:12px;margin:6px 0 0">Produkty dołączonych zamówień edytujesz na ich stronach. Faktura lub paragon wystawiony na tym zamówieniu obejmuje całą paczkę (także dołączone zamówienia, jeśli nie mają już własnego dokumentu).</p>
    <?php endif; ?>
    <?php if (canEdit()): ?>
    <div id="itemsEditor" hidden style="margin-top:16px;padding-top:14px;border-top:1px solid var(--line)">
        <?php if ($items !== []): ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="save_items">
            <table>
                <tr><th>Nazwa</th><th>SKU</th><th style="width:90px">Ilość</th><th></th></tr>
                <?php foreach ($items as $i => $it): ?>
                    <tr>
                        <td><input type="text" name="item_name[<?= (int) $i ?>]" value="<?= $g($it, 'name') ?>" maxlength="250" aria-label="Nazwa pozycji" style="width:100%;min-width:180px;box-sizing:border-box"></td>
                        <td><?= $g($it, 'sku', '—') ?></td>
                        <td><input type="number" name="qty[<?= (int) $i ?>]" value="<?= (int)($it['quantity'] ?? 1) ?>" min="0" style="width:75px"></td>
                        <td>
                            <button type="submit" name="remove_index" value="<?= (int) $i ?>" class="btn danger" style="font-size:12px;padding:5px 9px" onclick="return confirm('Usunąć tę pozycję z zamówienia?')">Usuń</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <p style="margin-top:12px"><button type="submit" class="btn" style="font-size:13px">Zapisz zmiany</button></p>
        </form>
        <?php endif; ?>

        <div style="margin-top:<?= $items !== [] ? '16px;padding-top:14px;border-top:1px solid var(--line)' : '0' ?>">
            <strong style="font-size:13px">Dodaj produkt</strong>
            <form method="post" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-top:8px">
                <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="add_item">
                <div>
                    <label style="font-size:11px;color:var(--ink-2);display:block">Produkt (SKU z magazynu CRM)</label>
                    <input list="productPicker" name="add_sku" placeholder="Szukaj po SKU lub nazwie..." style="min-width:260px" autocomplete="off">
                    <datalist id="productPicker">
                        <?php foreach ($catalog as $p): ?>
                            <option value="<?= htmlspecialchars($p['sku']) ?>"><?= htmlspecialchars(($p['name'] ?? $p['sku']) . ' — ' . number_format((float)($p['pase_price'] ?? 0), 2) . ' ' . $currency) ?></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div>
                    <label style="font-size:11px;color:var(--ink-2);display:block">Ilość</label>
                    <input type="number" name="add_qty" value="1" min="1" style="width:75px">
                </div>
                <button type="submit" class="btn secondary">Dodaj do zamówienia</button>
            </form>
            <?php if ($catalog === []): ?>
                <p style="color:#888;font-size:12px;margin-top:8px">Magazyn CRM (Magazyn → Produkty) jest pusty — brak produktów do dodania.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ===== Wiadomości z klientem ===== -->
<div class="card sortable-section" data-section="messages">
    <div class="drag-handle">⠿</div>
    <strong>Wiadomości z klientem</strong><?php if ($thread !== []): ?> <span style="color:#888;font-size:12px">(<?= count($thread) ?>)</span><?php endif; ?>

    <?php if ($clientLink): ?>
        <div style="margin:8px 0 12px;font-size:12px;color:#555;background:#f6f8fb;border:1px solid #e7ebf0;border-radius:8px;padding:8px 10px">
            Link dla klienta:
            <div style="display:flex;gap:6px;margin-top:4px">
                <input id="clientLinkInput" value="<?= htmlspecialchars($clientLink) ?>" readonly onclick="this.select()" style="flex:1;font-size:12px">
                <button type="button" class="btn secondary" id="copyClientLink" style="padding:6px 12px;font-size:12px;white-space:nowrap">📋 Kopiuj</button>
            </div>
        </div>
    <?php else: ?>
        <p style="font-size:12px;color:#b06000;margin:8px 0 10px">Ustaw <a href="email_settings.php">adres bazowy strony</a>, aby wygenerować link dla klienta.</p>
    <?php endif; ?>

    <div class="msg-thread" id="msgThread">
        <?php if ($thread === []): ?>
            <p style="color:#888;font-size:13px">Brak wiadomości.</p>
        <?php endif; ?>
        <?php foreach ($thread as $m): $isStaff = $m['sender'] === 'staff'; ?>
            <?php if ($m['sender'] === 'auto'): ?>
            <div class="msg-bubble is-auto">
                <div style="font-size:11px;color:#888;margin-bottom:2px">
                    <span class="msg-auto-tag">Automatyczna</span>
                    <?php if ($m['template'] !== ''): ?><strong><?= htmlspecialchars($m['template']) ?></strong><?php endif; ?>
                    · <?= htmlspecialchars(($t = strtotime((string) $m['created_at'])) ? date('d.m.Y H:i', $t) : (string) $m['created_at']) ?>
                </div>
                <div class="msg-body">✉️ <?= htmlspecialchars($m['body']) ?></div>
                <div style="font-size:11px;color:#888;margin-top:2px">
                    do <?= htmlspecialchars($m['recipient']) ?>
                    <?php if ($m['failed']): ?> · <span style="color:#b3261e" title="<?= htmlspecialchars($m['error']) ?>">nie wysłano</span><?php endif; ?>
                </div>
            </div>
            <?php continue; endif; ?>
            <div class="msg-bubble <?= $isStaff ? 'is-staff' : 'is-client' ?>">
                <div style="font-size:11px;color:#888;margin-bottom:2px">
                    <strong><?= htmlspecialchars($isStaff ? ('Ty / ' . ($m['author_name'] ?? 'obsługa')) : ($m['author_name'] ?: 'Klient')) ?></strong>
                    · <?= htmlspecialchars(($t = strtotime((string) $m['created_at'])) ? date('d.m.Y H:i', $t) : (string) $m['created_at']) ?>
                    <?php if ($isStaff && canEdit()): ?>
                        <form method="post" style="display:inline" onsubmit="return confirm('Usunąć tę wiadomość?')">
                            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action" value="delete_message">
                            <input type="hidden" name="message_id" value="<?= (int)$m['id'] ?>">
                            <button type="submit" class="staff-msg-del" title="Usuń wiadomość">usuń</button>
                        </form>
                    <?php endif; ?>
                </div>
                <div class="msg-body"><?= \Pase\Support\TextLinks::html((string) $m['body']) ?></div>
                <?php if (!empty($m['attachment_path'])): ?>
                    <div style="margin-top:6px"><a href="attachment.php?id=<?= (int)$m['id'] ?>" style="font-size:12px">📎 <?= htmlspecialchars($m['attachment_name'] ?? 'załącznik') ?></a></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if (canEdit()): ?>
    <form method="post" enctype="multipart/form-data" style="margin-top:10px">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="send_message">
        <div class="tpl-pick" id="tplPick">
            <label for="tplPickBtn" style="font-size:12px;color:var(--ink-2)">Szablon:</label>
            <div class="tpl-pick-box">
                <button type="button" id="tplPickBtn" class="tpl-pick-btn" aria-haspopup="listbox" aria-expanded="false">
                    <span id="tplPickLabel">Nie wybrano</span><span aria-hidden="true">▾</span>
                </button>
                <div class="tpl-pick-panel" id="tplPickPanel" hidden>
                    <input type="search" id="tplPickSearch" placeholder="Szukaj szablonu..." autocomplete="off">
                    <div class="tpl-pick-list" id="tplPickList" role="listbox">
                        <div class="tpl-pick-opt" role="option" data-id="0">Nie wybrano</div>
                        <div class="tpl-pick-group">Szablony e-mail</div>
                        <?php foreach ($replyTemplates as $rt): ?>
                            <div class="tpl-pick-opt" role="option" data-id="<?= (int) $rt['id'] ?>"><?= htmlspecialchars($rt['name']) ?></div>
                        <?php endforeach; ?>
                        <?php if ($replyTemplates === []): ?>
                            <div class="tpl-pick-empty">Brak aktywnych szablonów.</div>
                        <?php endif; ?>
                        <div class="tpl-pick-empty" id="tplPickNone" hidden>Nic nie znaleziono.</div>
                    </div>
                    <a href="email_templates.php" class="tpl-pick-manage">Zarządzaj szablonami ↗</a>
                </div>
            </div>
        </div>
        <script type="application/json" id="tplPickData"><?= json_encode($replyTemplates, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
        <input type="hidden" name="template_id" id="tplPickId" value="0">
        <p id="tplPickInvoice" class="tpl-pick-invoice" hidden>📎 Do e-maila zostanie dołączona faktura z wFirma (PDF).</p>
        <textarea name="message_body" id="msgBody" rows="3" style="width:100%" placeholder="Napisz odpowiedź do klienta..."></textarea>
        <div style="display:flex;gap:8px;align-items:center;margin-top:8px;flex-wrap:wrap">
            <input type="file" name="attachment">
            <button class="btn" type="submit" style="margin-left:auto">Wyślij do klienta</button>
        </div>
        <?php if (\Pase\Services\AllegroOrderMessage::isAllegro($row)): ?>
        <p style="color:#888;font-size:11px;margin-top:6px">Wiadomość trafia do wątku i do kupującego w Centrum wiadomości Allegro (przy tym zamówieniu). Załącznik: PDF albo obraz.</p>
        <?php else: ?>
        <p style="color:#888;font-size:11px;margin-top:6px">Wiadomość trafia do wątku i jest wysyłana e-mailem do klienta (wymaga skonfigurowanego SMTP).</p>
        <?php endif; ?>
    </form>
    <?php endif; ?>
</div>

<?php $renderExtSections($extSections['left']); ?>

</div><!-- /layout-col left -->
<div class="layout-col" data-col="right">

<!-- ===== Przesyłki ===== -->
<div class="card sortable-section" data-section="shipping">
    <div class="drag-handle">⠿</div>
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding-right:30px">
        <strong>Przesyłki</strong>
        <?php if (canEdit()): ?>
        <form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="set_no_shipping">
            <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer" title="Zamówienie wirtualne - bez wysyłki"><input type="checkbox" name="no_shipping" value="1" <?= !empty($row['no_shipping']) ? 'checked' : '' ?> onchange="this.form.submit()"> Wirtualne</label>
        </form>
        <?php elseif (!empty($row['no_shipping'])): ?><span class="pill muted">wirtualne</span><?php endif; ?>
    </div>

    <?php $plannedShip = substr((string) ($row['planned_ship_date'] ?? ''), 0, 10); ?>
    <?php if (canEdit()): ?>
    <form method="post" class="planned-ship" style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:8px;font-size:13px"
          title="Klient zobaczy na swojej stronie zamówienia: „Twoje zamówienie zostanie nadane …” (do czasu nadania paczki)">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="set_planned_ship_date">
        <label for="plannedShipDate">Planowana data nadania:</label>
        <input type="date" id="plannedShipDate" name="planned_ship_date" value="<?= htmlspecialchars($plannedShip) ?>" style="padding:3px 6px;font-size:13px">
        <button class="btn secondary" type="submit" style="padding:3px 10px;font-size:12px">Zapisz</button>
        <?php if ($plannedShip !== ''): ?><button class="btn secondary" type="submit" name="planned_reset" value="1" style="padding:3px 8px;font-size:12px" title="Usuń datę">✕</button><?php endif; ?>
    </form>
    <?php elseif ($plannedShip !== ''): ?>
    <div style="font-size:13px;margin-top:8px">Planowana data nadania: <strong><?= htmlspecialchars(date('d.m.Y', strtotime($plannedShip))) ?></strong></div>
    <?php endif; ?>

    <?php if ($tiktokLink !== null):
        $ttState = (string) ($tiktokLink['tracking_state'] ?? '');
        $ttShip = strtoupper((string) ($o['tiktok']['shipping_type'] ?? 'SELLER'));
    ?>
        <div style="font-size:13px;margin-top:8px;padding:8px 10px;border-radius:8px;background:<?= $ttState === 'error' ? '#fdecea' : '#f4f5f7' ?>">
            🎵 TikTok Shop:
            <?php if ($ttState === 'sent'): ?>numer <strong><?= htmlspecialchars((string) $tiktokLink['tracking_number']) ?></strong> wysłany do TikTok, zamówienie oznaczone jako wysłane.
            <?php elseif ($ttState === 'error'): ?>numer <strong><?= htmlspecialchars((string) $tiktokLink['tracking_number']) ?></strong> nie przeszedł: <?= htmlspecialchars((string) $tiktokLink['tracking_error']) ?>
                <?php if (canEdit()): ?><form method="post" style="display:inline;margin-left:6px"><input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="tiktok_retry_tracking"><button class="btn secondary" type="submit" style="padding:2px 8px;font-size:12px">Spróbuj ponownie</button></form><?php endif; ?>
            <?php elseif ($ttState === 'skipped' || $ttShip === 'TIKTOK'): ?>wysyłka przez TikTok (etykieta TikTok) — numer nadaje TikTok.
            <?php elseif (($tiktokLink['tt_status'] ?? '') === 'AWAITING_SHIPMENT'): ?>po dodaniu przesyłki z numerem CRM wyśle go do TikTok (do <?= \Pase\Services\TiktokShop::RUN_EVERY_MIN ?> min).
            <?php else: ?>status w TikTok: <?= htmlspecialchars(\Pase\Services\TiktokShop::STATUS_LABELS[(string) ($tiktokLink['tt_status'] ?? '')] ?? (string) ($tiktokLink['tt_status'] ?? '')) ?>.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($shipments): ?>
        <table style="margin-top:10px">
            <tr><th>Nr listu</th><th>Kurier</th><th>Koszt</th><th>Status</th><th>Etykieta</th><th></th></tr>
            <?php foreach ($shipments as $sh):
                $cancelled = ($sh['status'] ?? '') === 'cancelled';
                $shStatus  = (string) ($sh['status'] ?? 'created');
                $failed    = $shStatus === 'error';
                $pending   = $shStatus === 'pending';
            ?>
                <tr style="<?= $cancelled ? 'opacity:.55' : '' ?>">
                    <td><?php $trkUrl = \Pase\Services\ShipmentTracking::trackingUrl($sh); ?>
                        <?php if ($trkUrl !== null && !$cancelled): ?>
                            <a class="waybill-link" href="<?= htmlspecialchars($trkUrl) ?>" target="_blank" rel="noopener noreferrer" title="Śledź na stronie przewoźnika"><strong><?= htmlspecialchars((string) $sh['waybill_no']) ?></strong> ↗</a>
                        <?php else: ?><strong><?= htmlspecialchars($sh['waybill_no'] ?? '—') ?></strong><?php endif; ?></td>
                    <td><?= htmlspecialchars(trim((string) preg_replace('/\s*\(ręcznie\)\s*$/u', '', (string) ($sh['courier_code'] ?? ''))) ?: '—') ?></td>
                    <td><?php
                        $shQuoted = (json_decode((string) ($sh['request_payload'] ?? ''), true) ?: [])['quoted_price'] ?? null;
                        if ($sh['price'] !== null): ?><?= htmlspecialchars(number_format((float) $sh['price'], 2, ',', ' ')) ?> zł
                        <?php elseif ($shQuoted !== null && !$cancelled): ?><span title="Szacunek przed nadaniem — faktyczny koszt pojawi się, gdy przewoźnik go rozliczy" style="color:#888">~<?= htmlspecialchars(number_format((float) $shQuoted, 2, ',', ' ')) ?> zł</span>
                        <?php else: ?>—<?php endif; ?></td>
                    <td>
                        <?php if ($cancelled): ?><span class="pill bad">anulowana</span>
                        <?php elseif ($failed): ?><span class="pill bad" title="Powód w logach">błąd nadania</span>
                        <?php elseif ($pending): ?><span class="pill muted" title="Numer listu pojawi się po odświeżeniu">przewoźnik tworzy…</span>
                        <?php else:
                            $trk = (string) ($sh['tracking_status'] ?? '');
                            $trkEvents = json_decode((string) ($sh['tracking_events'] ?? ''), true) ?: [];
                            $trkLast = $trkEvents ? end($trkEvents) : null;
                            $trkTz  = new DateTimeZone('Europe/Warsaw');
                            $trkFmt = static fn(string $utc): string => $utc === '' ? ''
                                : (new DateTime($utc, new DateTimeZone('UTC')))->setTimezone($trkTz)->format('d.m H:i');
                            $noCarrier = ($sh['tracking_code'] ?? '') === \Pase\Services\ShipmentTracking::NO_CARRIER;
                            $hasInfo = $trkEvents !== [] || $noCarrier || !empty($sh['tracking_checked_at']);
                            $pillCls = $trk !== '' ? \Pase\Services\ShipmentTracking::pillClass($trk) : 'ok';
                            $pillTxt = $trk !== '' ? \Pase\Services\ShipmentTracking::label($trk) : ($shStatus === 'created' ? 'nadana' : $shStatus);
                            ?>
                            <span class="trk">
                                <?php if ($hasInfo): ?>
                                    <button type="button" class="pill <?= $pillCls ?> trk-pill" aria-expanded="false"><?= htmlspecialchars($pillTxt) ?></button>
                                    <span class="trk-pop" role="tooltip">
                                        <?php if ($noCarrier): ?>
                                            <span class="trk-note">Nie rozpoznano przewoźnika „<?= htmlspecialchars((string) $sh['courier_code']) ?>". Śledzenie działa dla InPost, DPD, DHL, GLS, UPS, FedEx, Poczty Polskiej, Orlen i Allegro One.</span>
                                        <?php else: ?>
                                            <span class="trk-head">
                                                <?= htmlspecialchars((string) ($sh['tracking_carrier'] ?? '')) ?>
                                                <?php if (!empty($sh['tracking_at'])): ?> · ostatnia zmiana <?= htmlspecialchars($trkFmt((string) $sh['tracking_at'])) ?><?php endif; ?>
                                            </span>
                                            <?php if ($trkEvents): ?>
                                                <ul>
                                                    <?php foreach (array_reverse($trkEvents) as $ev): ?>
                                                        <li><span><?= htmlspecialchars($ev['at'] !== '' ? $trkFmt(date('Y-m-d H:i:s', strtotime($ev['at']))) : '') ?></span> <?= htmlspecialchars($ev['desc'] !== '' ? $ev['desc'] : $ev['code']) ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php else: ?>
                                                <span class="trk-note">Przewoźnik nie ma jeszcze informacji o tej przesyłce.</span>
                                            <?php endif; ?>
                                            <?php if (!empty($sh['tracking_checked_at'])): ?>
                                                <span class="trk-checked">Sprawdzono <?= htmlspecialchars($trkFmt((string) $sh['tracking_checked_at'])) ?></span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="pill <?= $pillCls ?>"><?= htmlspecialchars($pillTxt) ?></span>
                                <?php endif; ?>
                            </span>
                            <?php if (!empty($sh['waybill_no'])): ?>
                                <button type="button" class="track-ship" data-ship="<?= (int) $sh['id'] ?>" title="Sprawdź teraz etap u przewoźnika">↻</button>
                            <?php endif; ?>
                            <?php if (canEdit() && $shStatus === 'created'): ?>
                                <select class="ship-status-set" data-ship="<?= (int) $sh['id'] ?>" title="Ustaw status ręcznie (gdy przewoźnik nie podaje danych)" aria-label="Ustaw status ręcznie">
                                    <option value="">✎</option>
                                    <?php foreach (\Pase\Services\ShipmentTracking::STATUSES as $stKey => [$stLabel]): ?>
                                        <option value="<?= htmlspecialchars($stKey) ?>"><?= htmlspecialchars($stLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($cancelled || $failed || $pending): ?>—
                        <?php elseif (!empty($sh['bl_order_id'])): ?>
                            <div class="lbl-menu">
                                <button type="button" class="btn secondary lbl-toggle" aria-haspopup="true" aria-expanded="false" style="padding:4px 10px;font-size:13px">🖨 Etykieta ▾</button>
                                <div class="lbl-pop" hidden>
                                    <label class="lbl-pop-title">Format</label>
                                    <select class="lbl-fmt" data-ship="<?= (int)$sh['id'] ?>">
                                        <option value="A4" <?= $defaultLabelFmt === 'A4' ? 'selected' : '' ?>>A4 (zwykła drukarka)</option>
                                        <option value="LBL" <?= $defaultLabelFmt === 'LBL' ? 'selected' : '' ?>>Termiczna PDF (A6)</option>
                                        <option value="ZPL" <?= $defaultLabelFmt === 'ZPL' ? 'selected' : '' ?>>ZPL (Zebra)</option>
                                        <option value="EPL" <?= $defaultLabelFmt === 'EPL' ? 'selected' : '' ?>>EPL (Zebra)</option>
                                    </select>
                                    <a class="lbl-item lbl-print" data-ship="<?= (int)$sh['id'] ?>" href="#">🖨 Drukuj</a>
                                    <a class="lbl-item lbl-agent" data-ship="<?= (int)$sh['id'] ?>" href="#" title="ZPL/EPL idą surowo, PDF agent zamieni na obraz">📠 Wyślij na drukarkę (agent druku)</a>
                                    <a class="lbl-item lbl-open" data-ship="<?= (int)$sh['id'] ?>" href="#">⬇ Pobierz / otwórz plik</a>
                                </div>
                            </div>
                        <?php elseif (!empty($sh['label_link'])): ?>
                            <a href="<?= htmlspecialchars($sh['label_link']) ?>" target="_blank" rel="noopener">⬇ PDF</a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td style="text-align:right">
                        <?php $manualShip = empty($sh['integration_id']); ?>
                        <?php if (!$cancelled && !$pending && canEdit() && ($failed || $manualShip || !empty($sh['bl_order_id']))): ?>
                            <button class="btn danger cancel-ship" data-ship="<?= (int)$sh['id'] ?>" data-failed="<?= ($failed || $manualShip) ? '1' : '0' ?>" type="button" style="padding:4px 10px;font-size:12px"><?= ($failed || $manualShip) ? 'Usuń' : 'Anuluj' ?></button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        <hr style="margin:14px 0;border:0;border-top:1px solid #eee">
    <?php else: ?>
        <p style="color:#888;font-size:13px;margin:6px 0 12px">Nie nadano jeszcze paczki — wybierz przewoźnika poniżej.</p>
    <?php endif; ?>

    <?php if ($mergedTarget !== null): ?>
        <p style="color:#b06000;font-size:13px">To zamówienie jedzie w paczce zamówienia <a href="order_view.php?id=<?= (int) $mergedTarget['woo_order_id'] ?>"><?= htmlspecialchars(\Pase\Services\OrderMerge::label($mergedTarget)) ?></a> — przesyłkę nadaj tam. Jeśli ma iść osobno, najpierw je odłącz.</p>
    <?php else: ?>
    <?php
    // „Nadaj przez": każde aktywne konto wtyczki kurierskiej obsługującej to zamówienie + „Inne" (ręcznie).
    $blpaczkaOffered = $hasBlpaczka && array_filter($courierOptions, static fn($co) => $co['type'] === 'blpaczka') !== [];
    ?>
    <?php if ($courierOptions === []): ?>
        <p style="color:#b06000;font-size:13px">Brak aktywnej integracji kurierskiej dla tego zamówienia. Dodaj <strong>BLPaczka</strong>, <strong>Wysyłam z Allegro</strong> albo <strong>ORLEN Paczka</strong> w <a href="integrations.php">Integracjach</a>.</p>
    <?php endif; ?>
    <?php if (canEdit()): ?>
        <div style="margin:2px 0 10px">
            <label style="font-size:11px;color:#888;display:block;margin-bottom:4px">Nadaj przez</label>
            <div class="ship-tabs" role="tablist">
                <?php foreach ($courierOptions as $co):
                    $key = $co['type'] === 'blpaczka' ? 'blpaczka' : 'acc-' . (int) $co['account']['id'];
                    if ($co['type'] === 'blpaczka' && !$blpaczkaOffered) { continue; } ?>
                    <button type="button" class="ship-tab" data-tab="<?= htmlspecialchars($key) ?>" style="--acc:<?= htmlspecialchars($co['color']) ?>">
                        <?= $co['icon'] ?> <?= htmlspecialchars($co['type'] === 'blpaczka' ? $co['name'] : ($co['account']['name'] ?: $co['name'])) ?>
                    </button>
                <?php endforeach; ?>
                <button type="button" class="ship-tab" data-tab="manual" style="--acc:#6b7280">✍️ Inne</button>
            </div>
        </div>

        <div class="ship-pane" data-pane="manual" data-order="<?= (int) $wooOrderId ?>" id="manualShip" hidden>
            <strong style="font-size:13px">Przesyłka nadana poza systemem</strong>
            <p style="font-size:12px;color:#888;margin:2px 0 0">Np. nadana ręcznie w panelu przewoźnika na własnej umowie — wpisz numer, żeby był przy zamówieniu. Etap przesyłki sprawdzimy od razu (śledzenie Allegro), a przy zamówieniach z Allegro koszt pobierze się z rozliczeń Allegro. Etykiety nie pobierzemy — zostaje w panelu, w którym paczkę nadano.</p>
            <div style="display:grid;grid-template-columns:200px 1fr;gap:8px;margin-top:8px">
                <div><label style="font-size:11px;color:#888;display:block">Przewoźnik</label>
                    <input id="ms_carrier" type="text" list="ms_carriers" placeholder="np. InPost" style="width:100%">
                    <datalist id="ms_carriers"><option>InPost</option><option>DPD</option><option>DHL</option><option>GLS</option><option>UPS</option><option>Poczta Polska</option><option>Orlen Paczka</option><option>FedEx</option><option>Allegro One</option></datalist></div>
                <div><label style="font-size:11px;color:#888;display:block">Numer przesyłki</label>
                    <input id="ms_waybill" type="text" maxlength="100" style="width:100%"></div>
            </div>
            <div style="display:flex;gap:8px;margin-top:12px;align-items:center">
                <button class="btn" type="button" id="btnManualShip">Zapisz przesyłkę</button>
                <span id="msMsg" style="font-size:13px"></span>
            </div>
        </div>

        <?php foreach ($courierOptions as $co): if ($co['type'] === 'blpaczka') { continue; }
            $key = 'acc-' . (int) $co['account']['id'];
            $accLbl = strtoupper((string) ($co['account']['config']['label_format'] ?? 'PDF')) === 'ZPL' ? 'ZPL' : 'PDF'; ?>
            <div class="ship-pane courier-pane" data-pane="<?= $key ?>" data-integration="<?= (int) $co['account']['id'] ?>" data-order="<?= (int) $wooOrderId ?>" hidden>
                <?php if ($co['type'] === 'allegro_wysylka'): ?>
                    <p style="font-size:12px;color:#666;margin:0">
                        Metoda dostawy wybrana przez kupującego: <strong><?= htmlspecialchars($allegroDeliveryMethod ?? '—') ?></strong><?php if (!empty($allegroPickupPoint['id'])): ?> · punkt <strong><?= htmlspecialchars((string) $allegroPickupPoint['id']) ?></strong><?php endif; ?>
                    </p>
                <?php endif; ?>
                <span class="cp-info" style="display:block;font-size:12px;color:#888;margin-top:2px">⏳ pobieram dostępne umowy…</span>
                <div style="margin-top:8px">
                    <label style="font-size:11px;color:#888;display:block">Umowa / usługa</label>
                    <select class="cp-service" style="width:100%;max-width:480px"><option value="">⏳ ładuję…</option></select>
                </div>
                <div class="cp-point-row" style="display:none;margin-top:10px">
                    <label class="cp-point-label" style="font-size:11px;color:#888;display:block">Punkt odbioru</label>
                    <input class="cp-point" type="text" maxlength="40" autocomplete="off" list="cp-points-<?= (int) $co['account']['id'] ?>" style="width:100%;max-width:480px">
                    <datalist id="cp-points-<?= (int) $co['account']['id'] ?>"></datalist>
                    <span class="cp-point-note" style="display:block;font-size:12px;color:#888;margin-top:2px"></span>
                </div>
                <div class="cp-handover-row" style="display:none;margin-top:10px">
                    <label style="font-size:11px;color:#888;display:block;margin-bottom:4px">Sposób nadania</label>
                    <div class="cp-handover" style="display:flex;flex-direction:column;gap:4px"></div>
                    <div class="cp-pickup" style="display:none;margin-top:6px">
                        <label style="font-size:11px;color:#888;display:block">Dzień odbioru przez kuriera</label>
                        <input class="cp-pickup-date" type="date" value="<?= date('Y-m-d', strtotime('+1 weekday')) ?>" min="<?= date('Y-m-d') ?>" style="width:180px">
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:8px">
                    <div><label style="font-size:11px;color:#888">Waga (kg)</label><input class="cp-weight" type="number" step="0.1" value="<?= htmlspecialchars($defPkg['weight']) ?>" style="width:100%"></div>
                    <div><label style="font-size:11px;color:#888">Dł. (cm)</label><input class="cp-x" type="number" value="<?= htmlspecialchars($defPkg['x']) ?>" style="width:100%"></div>
                    <div><label style="font-size:11px;color:#888">Szer. (cm)</label><input class="cp-y" type="number" value="<?= htmlspecialchars($defPkg['y']) ?>" style="width:100%"></div>
                    <div><label style="font-size:11px;color:#888">Wys. (cm)</label><input class="cp-z" type="number" value="<?= htmlspecialchars($defPkg['z']) ?>" style="width:100%"></div>
                </div>
                <div style="display:grid;grid-template-columns:160px 1fr;gap:8px;margin-top:8px">
                    <div><label style="font-size:11px;color:#888;display:block">Format etykiety</label>
                        <select class="cp-label" style="width:100%">
                            <option value="PDF" <?= $accLbl === 'PDF' ? 'selected' : '' ?>>PDF</option>
                            <option value="ZPL" <?= $accLbl === 'ZPL' ? 'selected' : '' ?>>ZPL (Zebra)</option>
                        </select></div>
                    <div><label style="font-size:11px;color:#888;display:block">Tekst na etykiecie (opcjonalnie)</label>
                        <input class="cp-text" type="text" maxlength="100" placeholder="domyślnie z ustawień integracji" style="width:100%"></div>
                </div>
                <div class="cp-quote" style="margin-top:12px;font-size:13px;color:#555">Szacowany koszt: <strong class="cp-price">—</strong> <span class="cp-price-src" style="color:#888;font-size:12px"></span></div>
                <div style="display:flex;gap:8px;margin-top:8px;align-items:center;flex-wrap:wrap">
                    <button class="btn cp-send" type="button" style="background:<?= htmlspecialchars($co['color']) ?>"><?= $co['icon'] ?> Nadaj przez <?= htmlspecialchars($co['name']) ?></button>
                    <span class="cp-msg" style="font-size:13px"></span>
                </div>
                <?php if ($co['type'] === 'allegro_wysylka'): ?>
                    <p style="color:#888;font-size:11px;margin-top:8px">Koszt nalicza Allegro (umowa Allegro) albo przewoźnik (własna umowa). Numer przesyłki Allegro samo dopina do zamówienia. Formatu etykiety nie da się zmienić po nadaniu.</p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($blpaczkaOffered && canEdit()): ?>
        <div id="shipForm" class="ship-pane" data-pane="blpaczka" data-order="<?= (int)$wooOrderId ?>" hidden>
            <strong style="font-size:13px">Nadaj nową paczkę</strong>
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:8px">
                <div><label style="font-size:11px;color:#888">Waga (kg)</label><input id="sp_weight" type="number" step="0.1" value="<?= htmlspecialchars($defPkg['weight']) ?>" style="width:100%"></div>
                <div><label style="font-size:11px;color:#888">Dł. X (cm)</label><input id="sp_x" type="number" value="<?= htmlspecialchars($defPkg['x']) ?>" style="width:100%"></div>
                <div><label style="font-size:11px;color:#888">Szer. Y (cm)</label><input id="sp_y" type="number" value="<?= htmlspecialchars($defPkg['y']) ?>" style="width:100%"></div>
                <div><label style="font-size:11px;color:#888">Wys. Z (cm)</label><input id="sp_z" type="number" value="<?= htmlspecialchars($defPkg['z']) ?>" style="width:100%"></div>
            </div>
            <div style="margin-top:8px">
                <label style="font-size:11px;color:#888;display:block">Kurier</label>
                <div id="favCourierBtns" data-fav='<?= htmlspecialchars(json_encode($blInteg['config']['favorite_couriers'] ?? []), ENT_QUOTES) ?>' style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px"></div>
                <select id="sp_courier" style="width:100%;max-width:360px">
                    <option value="">⏳ ładuję listę kurierów...</option>
                </select>
            </div>
            <div style="margin-top:8px;max-width:360px">
                <label style="font-size:11px;color:#888;display:block">Punkt odbioru (paczkomat / ORLEN Paczka) — ID</label>
                <input id="sp_point" value="<?= htmlspecialchars($pickupPoint['id'] ?? '') ?>" placeholder="puste = dostawa pod adres" style="width:100%">
            </div>
            <?php $defPickupMode = ($blInteg['config']['pickup_mode'] ?? 'courier') === 'self' ? 'self' : 'courier'; ?>
            <div style="margin-top:10px">
                <label style="font-size:11px;color:#888;display:block;margin-bottom:4px">Sposób nadania</label>
                <label style="font-size:13px;margin-right:14px;cursor:pointer">
                    <input type="radio" name="sp_pickup_mode" value="courier" <?= $defPickupMode === 'courier' ? 'checked' : '' ?>> Odbiór przez kuriera
                </label>
                <label style="font-size:13px;cursor:pointer">
                    <input type="radio" name="sp_pickup_mode" value="self" <?= $defPickupMode === 'self' ? 'checked' : '' ?>> Sam dostarczę do przewoźnika
                </label>
            </div>
            <div id="pickupFields" style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:8px;max-width:480px<?= $defPickupMode === 'self' ? ';display:none' : '' ?>">
                <div><label style="font-size:11px;color:#888;display:block">Dzień odbioru</label>
                    <input id="sp_pickup_date" type="date" value="<?= date('Y-m-d', strtotime('+1 day')) ?>" style="width:100%"></div>
                <div><label style="font-size:11px;color:#888;display:block">Kurier od (godz.)</label>
                    <input id="sp_pickup_from" type="number" min="8" max="20" value="9" style="width:100%"></div>
                <div><label style="font-size:11px;color:#888;display:block">Kurier do (godz.)</label>
                    <input id="sp_pickup_to" type="number" min="8" max="20" value="17" style="width:100%"></div>
            </div>
            <div style="display:flex;gap:8px;margin-top:12px;align-items:center;flex-wrap:wrap">
                <button class="btn" type="button" id="btnSend" style="background:#16a34a">📦 Nadaj paczkę</button>
                <span id="shipMsg" style="font-size:13px"></span>
            </div>
            <p id="shipHint" style="display:none;background:#fef3c7;color:#78350f;border-radius:8px;padding:8px 10px;font-size:12px;margin-top:8px"></p>
            <p id="shipBalance" style="color:#888;font-size:11px;margin-top:8px">Nadanie pobiera opłatę z BLPaczka wybraną w integracji formą płatności. ⏳ sprawdzam saldo skarbonki…</p>
            <pre id="shipDebug" style="display:none;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;overflow:auto;font-size:11px;max-height:300px;margin-top:10px"></pre>
        </div>
    <?php endif; ?>
    <?php endif; /* scalone do innego zamówienia */ ?>
</div>

<!-- ===== Klient + płatność ===== -->
<div class="card sortable-section" data-section="info">
    <div class="drag-handle">⠿</div>
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding-right:30px">
        <strong>Informacje o zamówieniu</strong>
        <?php if (canEdit()): ?>
            <button type="button" id="contactEditBtn" class="btn secondary" style="display:inline-flex;padding:7px;line-height:0" title="Edytuj dane kontaktowe" aria-label="Edytuj dane kontaktowe">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
            </button>
        <?php endif; ?>
    </div>
    <form method="post" id="contactForm">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="save_contact">
        <table style="margin-top:10px">
            <?php if ($orderCustomer !== null):
                $ocCount = (int) $orderCustomer['orders_count'];
                $ocChannels = implode(', ', array_map(static fn($c) => $c['label'] . ' × ' . (int) $c['count'], $orderCustomer['channels'])); ?>
            <tr><th>Klient</th><td>
                <style>.customer-card-link{color:inherit;font-weight:700;text-decoration:none}.customer-card-link:hover{color:var(--accent);text-decoration:underline;text-underline-offset:3px}</style>
                <a class="customer-card-link" href="customers.php?id=<?= (int) $orderCustomer['id'] ?>"><?= htmlspecialchars((string) ($orderCustomer['name'] ?: ((int) $orderCustomer['anonymized'] === 1 ? 'Klient (dane usunięte)' : 'Karta klienta'))) ?></a>
                · <?= $ocCount ?> <?= $ocCount === 1 ? 'zamówienie' : (in_array($ocCount % 10, [2, 3, 4], true) && !in_array($ocCount % 100, [12, 13, 14], true) ? 'zamówienia' : 'zamówień') ?>
                <?php if ($ocCount > 1): ?><span class="pill ok" style="font-size:11px">powracający</span><?php endif; ?>
                <?php if ($ocCount > 1 && $ocChannels !== ''): ?><div style="font-size:12px;color:#777;margin-top:2px"><?= htmlspecialchars($ocChannels) ?></div><?php endif; ?>
            </td></tr>
            <?php endif; ?>
            <tr><th>Numer CRM</th><td><strong><?= $row['pase_number'] !== null ? '#' . (int)$row['pase_number'] : '—' ?></strong></td></tr>
            <?php if ($isManualOrder): ?>
            <tr><th>Źródło</th><td><span class="pill ok" style="font-size:11px">dodane ręcznie w CRM</span> <?= $g($row, 'order_number') ?><?php if (!empty($o['crm_created_by'])): ?> <span style="color:#888">· <?= htmlspecialchars((string) $o['crm_created_by']) ?></span><?php endif; ?></td></tr>
            <?php $manualShopId = (int) ($row['manual_shop_id'] ?? 0); $manualShops = \Pase\Services\ManualOrders::shops($pdo, $manualShopId); ?>
            <?php if ($manualShops !== [] || $manualShopId > 0): ?>
            <tr><th>Sklep</th><td>
                <?php if (canEdit()): ?>
                <form method="post" style="display:inline-flex;gap:6px;align-items:center;margin:0">
                    <input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="set_manual_shop">
                    <select name="shop_id" onchange="this.form.submit()" style="padding:3px 6px;font-size:13px">
                        <option value="">— nie wybrano —</option>
                        <?php foreach ($manualShops as $msId => $msName): ?>
                            <option value="<?= (int) $msId ?>" <?= $manualShopId === (int) $msId ? 'selected' : '' ?>><?= htmlspecialchars($msName) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <noscript><button class="btn secondary" type="submit">Zapisz</button></noscript>
                </form>
                <?php else: ?><?= htmlspecialchars($manualShops[$manualShopId] ?? '—') ?><?php endif; ?>
            </td></tr>
            <?php endif; ?>
            <?php else: ?>
            <tr><th>Numer w sklepie</th><td><?= $g($row, 'order_number', (string)$wooOrderId) ?> <span style="color:#aaa">(Woo #<?= (int)$wooOrderId ?>)</span></td></tr>
            <?php endif; ?>
            <?php if (!$isManualOrder): ?><tr><th>Status w <?= $sourceType === 'allegro' ? 'Allegro' : ($sourceType === 'tiktokshop' ? 'TikTok Shop' : 'sklepie') ?></th><td><span class="pill muted" title="<?= htmlspecialchars((string) ($o['status'] ?? $row['status'] ?? '')) ?>"><?= htmlspecialchars(\Pase\Domain\OrderStatus::channelLabel($o, $row['status'] ?? null)) ?></span></td></tr><?php endif; ?>
            <tr>
                <th>E-mail</th>
                <td>
                    <input type="email" name="contact_email" id="contactEmailInput" class="inline-edit-field" value="<?= htmlspecialchars($contactEmail) ?>" placeholder="—" disabled>
                    <?php if ($isContactEdited): ?> <span class="pill warn" style="font-size:11px">poprawiono lokalnie</span><?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>Telefon</th>
                <td><input type="text" name="contact_phone" id="contactPhoneInput" class="inline-edit-field" value="<?= htmlspecialchars($contactPhone) ?>" placeholder="—" disabled></td>
            </tr>
            <tr><th>Metoda płatności</th><td><?= $g($o, 'payment_method_title', '—') ?></td></tr>
            <tr><th>Status płatności</th><td>
                <span class="pill <?= htmlspecialchars($paymentClass) ?>"><?= htmlspecialchars($paymentLabel) ?></span>
                <?php if ($manualPaid !== null): ?>
                    <span style="font-size:12px;color:var(--ink-2)" title="<?= htmlspecialchars('Wpisał(a): ' . ($row['paid_amount_by'] ?? '—') . ' · ' . ($row['paid_amount_at'] ?? '') . ' UTC') ?>">potwierdzone ręcznie w CRM</span>
                <?php endif; ?>
            </td></tr>
            <tr><th>Zapłacono</th><td>
                <?php
                    $paidShown = $manualPaid !== null ? $manualPaid['amount'] : ($paymentClass === 'ok' ? $groupTotal : 0.0);
                    $paidTone = \Pase\Services\OrderPayment::coversTotal((float) $paidShown, (float) $groupTotal) ? 'ok' : ($paidShown > 0 ? 'warn' : 'bad');
                    $canEditPaid = canEdit() && $mergedTarget === null;
                ?>
                <div class="paid-row" id="paidView">
                    <span class="paid-amount paid-<?= $paidTone ?>"><?= htmlspecialchars(number_format($paidShown, 2, '.', '')) ?> <?= htmlspecialchars($currency) ?></span>
                    <span>z <strong><?= htmlspecialchars(number_format($groupTotal, 2, '.', '')) ?> <?= htmlspecialchars($currency) ?></strong></span>
                    <?php if ($canEditPaid): ?>
                        <?php if ($manualPaid !== null): ?>
                            <button type="submit" form="paymentForm" name="paid_reset" value="1" class="btn secondary paid-icon" title="Usuń ręczną wpłatę i wróć do statusu ze sklepu" aria-label="Wróć do statusu ze sklepu" onclick="return confirm('Usunąć ręcznie wpisaną wpłatę? Status płatności znów będzie brany ze sklepu.')">
                                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg>
                            </button>
                        <?php endif; ?>
                        <button type="button" id="paidEditBtn" class="btn secondary paid-icon" title="Edytuj wpłatę" aria-label="Edytuj wpłatę">
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
                        </button>
                    <?php endif; ?>
                </div>
                <?php if ($canEditPaid): ?>
                <div class="paid-row" id="paidEdit" hidden>
                    <input type="text" inputmode="decimal" name="paid_amount" id="paidInput" form="paymentForm" class="paid-input" value="<?= htmlspecialchars(number_format($paidShown, 2, '.', '')) ?>" aria-label="Zapłacona kwota" autocomplete="off">
                    <span>z <?= htmlspecialchars(number_format($groupTotal, 2, '.', '')) ?> <?= htmlspecialchars($currency) ?></span>
                    <button type="submit" form="paymentForm" class="btn secondary paid-btn paid-save" title="Zapisz wpłatę" aria-label="Zapisz wpłatę">✓</button>
                    <button type="button" class="btn secondary paid-btn paid-full" data-amount="<?= htmlspecialchars(number_format($groupTotal, 2, '.', '')) ?>" title="Zapłacono całość">
                        <?= htmlspecialchars(number_format($groupTotal, 2, '.', '')) ?></button>
                    <button type="button" class="btn secondary paid-btn paid-zero" data-amount="0.00" title="Nic nie zapłacono">0.00</button>
                    <button type="button" id="paidCancelBtn" class="btn secondary paid-btn" title="Anuluj" aria-label="Anuluj">✕</button>
                </div>
                <div id="paidHint" hidden style="font-size:12px;color:var(--ink-2);margin-top:6px">Zapisuje się tylko w CRM — status w <?= $sourceType === 'allegro' ? 'Allegro' : 'sklepie' ?> się nie zmienia.<?= $absorbedOrders !== [] ? ' Kwota dotyczy całej paczki razem ze scalonymi zamówieniami.' : '' ?></div>
                <?php elseif ($mergedTarget !== null): ?>
                <div style="font-size:12px;color:var(--ink-2);margin-top:4px">Wpłatę za paczkę zaznaczasz na zamówieniu głównym <a href="order_view.php?id=<?= (int) $mergedTarget['woo_order_id'] ?>"><?= htmlspecialchars(\Pase\Services\OrderMerge::label($mergedTarget)) ?></a>.</div>
                <?php endif; ?>
            </td></tr>
            <?php if (!empty($paymentDate)): ?>
            <tr><th>Data płatności<?= $paymentDateIsUtc ? ' (UTC)' : '' ?></th><td><?= htmlspecialchars(str_replace('T', ' ', (string)$paymentDate)) ?></td></tr>
            <?php endif; ?>
            <tr><th>Suma zamówienia</th><td><strong><?= htmlspecialchars(number_format($groupTotal, 2)) ?> <?= htmlspecialchars($currency) ?></strong><?php if ($absorbedOrders !== []): ?> <span class="pill muted" style="font-size:11px" title="To zamówienie <?= htmlspecialchars(number_format($localTotal, 2)) ?> + scalone <?= htmlspecialchars(implode(', ', array_map(static fn(array $ab): string => \Pase\Services\OrderMerge::label($ab) . ' ' . number_format(\Pase\Services\OrderMerge::orderTotal($ab), 2), $absorbedOrders))) ?>">łącznie ze scalonymi</span><?php endif; ?><?php if ($isLocallyEdited): ?> <span class="pill warn" style="font-size:11px">edytowano lokalnie</span><?php endif; ?></td></tr>

            <tr><th>Data złożenia</th><td><?= $g($o, 'date_created', $row['date_created'] ?? '—') ?></td></tr>
            <tr><th>Pobrano do CRM (UTC)</th><td><?= $g($row, 'imported_at') ?></td></tr>
        </table>

        <?php if (canEdit()): ?>
        <div id="contactActions" hidden style="margin-top:14px;padding-top:14px;border-top:1px solid var(--line)">
            <p style="color:var(--ink-2);font-size:12px;margin:0 0 10px">Poprawka e-maila/telefonu zapisuje się tylko w CRM — nie zmienia danych w sklepie ani weryfikacji klienta na jego stronie zamówienia. Wyczyść oba pola i zapisz, aby wrócić do oryginału ze sklepu.</p>
            <button type="submit" class="btn" style="font-size:13px">Zapisz</button>
            <button type="button" id="contactCancelBtn" class="btn secondary" style="font-size:13px">Anuluj</button>
        </div>
        <?php endif; ?>
    </form>
    <?php if (canEdit() && $mergedTarget === null): ?>
    <form method="post" id="paymentForm" hidden>
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="set_paid_amount">
    </form>
    <style>
        .paid-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.paid-row[hidden]{display:none}
        .paid-amount{display:inline-block;padding:5px 14px;border-radius:6px;font-weight:700;color:#fff}
        .paid-amount.paid-ok{background:#2e6b43}.paid-amount.paid-warn{background:#b06000}.paid-amount.paid-bad{background:#b3261e}
        .paid-icon{display:inline-flex;padding:7px;line-height:0}
        .paid-input{width:110px;padding:6px 10px;font-size:14px}
        .paid-btn{padding:5px 10px;font-size:13px;font-weight:600}
        .btn.paid-full{color:#2e6b43;border:1px solid #2e6b43;background:transparent}.btn.paid-zero{color:#b3261e;border:1px solid #b3261e;background:transparent}
        .btn.paid-save{color:#1a5fb4;border:1px solid #1a5fb4;background:transparent}
    </style>
    <script>
    (function () {
        var view = document.getElementById('paidView'), edit = document.getElementById('paidEdit'),
            hint = document.getElementById('paidHint'), input = document.getElementById('paidInput'),
            form = document.getElementById('paymentForm');
        if (!view || !edit || !input || !form) { return; }
        function toggle(on) { view.hidden = on; edit.hidden = !on; hint.hidden = !on; if (on) { input.focus(); input.select(); } }
        document.getElementById('paidEditBtn').addEventListener('click', function () { toggle(true); });
        document.getElementById('paidCancelBtn').addEventListener('click', function () { toggle(false); });
        // Szybkie przyciski (całość / 0.00) od razu zapisują, jak w BaseLinker.
        edit.querySelectorAll('.paid-full, .paid-zero').forEach(function (b) {
            b.addEventListener('click', function () { input.value = b.dataset.amount; form.requestSubmit ? form.requestSubmit() : form.submit(); });
        });
        input.addEventListener('keydown', function (e) { if (e.key === 'Escape') { toggle(false); } });
    })();
    </script>
    <?php endif; ?>
</div>

<!-- ===== Adresy ===== -->
<div class="card sortable-section" data-section="addresses">
    <div class="drag-handle">⠿</div>
    <?php $deliveryMethodName = $allegroDeliveryMethod ?? (($o['shipping_lines'][0]['method_title'] ?? null) ?: null); ?>
    <?php if ($deliveryMethodName !== null): ?>
        <strong>Metoda dostawy</strong>
        <p style="margin:8px 0 16px;line-height:1.6"><?= htmlspecialchars((string) $deliveryMethodName) ?></p>
    <?php endif; ?>
    <div class="pp-box">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
            <strong>Odbiór w punkcie</strong>
            <?php if (canEdit()): ?>
                <span style="display:flex;gap:6px">
                    <?php if ($sourceType === 'allegro'): ?>
                        <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action" value="refresh_allegro_delivery">
                            <button class="btn secondary" type="submit" style="padding:3px 9px;font-size:12px" title="Pobierz jeszcze raz dane dostawy z Allegro">↻ z Allegro</button></form>
                    <?php endif; ?>
                    <?php if ($sourceType !== 'allegro' && !empty($row['integration_id'])): ?>
                        <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action" value="refresh_woo_delivery">
                            <button class="btn secondary" type="submit" style="padding:3px 9px;font-size:12px" title="Pobierz jeszcze raz dane dostawy ze sklepu">↻ ze sklepu</button></form>
                    <?php endif; ?>
                    <button class="btn secondary" type="button" style="padding:3px 9px;font-size:12px" onclick="var f=document.getElementById('ppEdit');f.hidden=!f.hidden">✎</button>
                </span>
            <?php endif; ?>
        </div>
        <?php if ($pickupPoint !== null): ?>
            <table class="pp-table">
                <?php if ($pickupPoint['name'] !== ''): ?><tr><th>Nazwa</th><td><?= htmlspecialchars($pickupPoint['name']) ?></td></tr><?php endif; ?>
                <tr><th>ID</th><td><strong><?= htmlspecialchars($pickupPoint['id']) ?></strong><?php if ($pickupEdited): ?> <span class="pill muted" title="Poprawione ręcznie w CRM">zmieniony</span><?php endif; ?></td></tr>
                <?php if ($pickupPoint['street'] !== ''): ?><tr><th>Adres</th><td><?= htmlspecialchars($pickupPoint['street']) ?></td></tr><?php endif; ?>
                <?php if (trim($pickupPoint['postcode'] . $pickupPoint['city']) !== ''): ?><tr><th>Kod i miasto</th><td><?= htmlspecialchars(trim($pickupPoint['postcode'] . ' ' . $pickupPoint['city'])) ?></td></tr><?php endif; ?>
            </table>
        <?php else: ?>
            <p style="margin:6px 0 0;color:#888;font-size:13px">Brak — dostawa pod adres<?= $sourceType === 'allegro' ? ' (jeśli kupujący wybrał punkt, kliknij „↻ z Allegro")' : '' ?>.</p>
            <?php
                // Metoda wygląda na punkt, a punktu nie znaleźliśmy: pokazujemy, co sklep przysłał,
                // żeby dało się wskazać pole, w którym wtyczka go trzyma.
                $ppLooksLikePoint = $sourceType !== 'allegro' && preg_match('/paczkomat|punkt|automat|locker|odbi[oó]r w/iu', (string) ($deliveryMethodName ?? ''));
                $ppRawMeta = [];
                if ($ppLooksLikePoint) {
                    foreach ($o['meta_data'] ?? [] as $m) { $ppRawMeta[] = ['zamówienie', $m]; }
                    foreach ($o['shipping_lines'] ?? [] as $sl) { foreach ($sl['meta_data'] ?? [] as $m) { $ppRawMeta[] = ['metoda wysyłki', $m]; } }
                }
            ?>
            <?php if ($ppLooksLikePoint): ?>
                <p style="margin:6px 0 0;color:#8a6d00;font-size:12px">Metoda dostawy to odbiór w punkcie, ale sklep nie przesłał kodu punktu w znanym polu. Kliknij „↻ ze sklepu” albo wpisz punkt ręcznie (✎).</p>
                <details style="margin-top:4px;font-size:12px"><summary style="cursor:pointer;color:#888">Pola dodatkowe ze sklepu (<?= count($ppRawMeta) ?>)</summary>
                    <table class="pp-table" style="font-size:11px">
                        <?php foreach ($ppRawMeta as [$ppSrc, $m]): if (!is_array($m)) { continue; } $mv = $m['value'] ?? ''; $mv = is_scalar($mv) ? (string) $mv : json_encode($mv, JSON_UNESCAPED_UNICODE); ?>
                            <tr><th><?= htmlspecialchars((string) ($m['key'] ?? '')) ?> <span style="color:#aaa;font-weight:normal">(<?= $ppSrc ?>)</span></th><td style="word-break:break-all"><?= htmlspecialchars(mb_substr((string) $mv, 0, 200)) ?></td></tr>
                        <?php endforeach; ?>
                    </table>
                </details>
            <?php endif; ?>
        <?php endif; ?>
        <?php if (canEdit()): $pe = $pickupPoint ?? ['id' => '', 'name' => '', 'street' => '', 'postcode' => '', 'city' => '']; ?>
            <form method="post" id="ppEdit" class="pp-edit" hidden>
                <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="save_pickup_point">
                <div><label>ID punktu</label><input name="pp_id" value="<?= htmlspecialchars($pe['id']) ?>" placeholder="np. KRA01M, 963253"></div>
                <div><label>Nazwa</label><input name="pp_name" value="<?= htmlspecialchars($pe['name']) ?>" placeholder="np. Automat ORLEN Paczka"></div>
                <div><label>Adres</label><input name="pp_street" value="<?= htmlspecialchars($pe['street']) ?>"></div>
                <div style="display:grid;grid-template-columns:110px 1fr;gap:6px">
                    <div><label>Kod</label><input name="pp_postcode" value="<?= htmlspecialchars($pe['postcode']) ?>"></div>
                    <div><label>Miasto</label><input name="pp_city" value="<?= htmlspecialchars($pe['city']) ?>"></div>
                </div>
                <p style="margin:6px 0 0;display:flex;gap:6px;align-items:center">
                    <button class="btn" type="submit" style="padding:4px 12px;font-size:13px">Zapisz punkt</button>
                    <span style="color:#888;font-size:11px">Puste ID = dane z zamówienia.</span>
                </p>
            </form>
        <?php endif; ?>
    </div>
    <strong>Adres dostawy</strong>
    <p style="margin:8px 0 16px;line-height:1.6"><?= $hasShipping ? $fmtAddr($shipping) : (!empty($row['local_invoice']) ? $fmtAddr($billing) : '<span style="color:#888">taki jak dane do faktury</span>') ?></p>
    <?php
        // Dane do faktury: poprawka z CRM (local_invoice) albo oryginał ze sklepu / Allegro - patrz InvoiceData.
        $inv = \Pase\Services\InvoiceData::resolve($o, $row['local_invoice'] ?? null);
        $invCountries = ['PL' => 'Polska', 'DE' => 'Niemcy', 'CZ' => 'Czechy', 'SK' => 'Słowacja', 'LT' => 'Litwa', 'LV' => 'Łotwa',
            'EE' => 'Estonia', 'UA' => 'Ukraina', 'AT' => 'Austria', 'BE' => 'Belgia', 'DK' => 'Dania', 'FR' => 'Francja',
            'NL' => 'Holandia', 'IE' => 'Irlandia', 'IT' => 'Włochy', 'ES' => 'Hiszpania', 'SE' => 'Szwecja', 'FI' => 'Finlandia',
            'NO' => 'Norwegia', 'GB' => 'Wielka Brytania', 'HU' => 'Węgry', 'RO' => 'Rumunia', 'US' => 'USA'];
        if (!isset($invCountries[$inv['country']])) { $invCountries[$inv['country']] = $inv['country']; }
        $invLines = array_filter([$inv['company'], $inv['name'], $inv['address_1'], trim($inv['postcode'] . ' ' . $inv['city']),
            $inv['state'] !== '' ? 'woj. ' . $inv['state'] : '', $inv['country']]);
    ?>
    <div class="inv-head">
        <strong>Dane do faktury</strong>
        <?php if ($inv['edited']): ?><span class="pill muted" title="Poprawione w CRM — sklep ma swoją wersję">zmienione w CRM</span><?php endif; ?>
        <?php if (canEdit()): ?>
            <button class="btn secondary inv-edit-btn" type="button" title="Edytuj dane do faktury" onclick="invToggle(true)">✎</button>
        <?php endif; ?>
    </div>
    <div id="invShow">
        <?php if (!$inv['wants']): ?><p class="inv-note">Klient nie prosi o fakturę<?= $inv['requested'] === null ? ' (brak informacji w zamówieniu)' : '' ?>.</p><?php endif; ?>
        <p style="margin:8px 0 0;line-height:1.6"><?= $invLines ? implode('<br>', array_map('htmlspecialchars', $invLines)) : '—' ?></p>
        <?php if ($inv['nip'] !== ''): ?><p style="margin:6px 0 0"><strong>NIP:</strong> <?= htmlspecialchars($inv['nip']) ?></p><?php endif; ?>
    </div>
    <?php if (canEdit()): ?>
    <form method="post" id="invEdit" class="inv-edit" hidden autocomplete="off">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="save_invoice_data">
        <label class="inv-check"><input type="checkbox" name="wants" value="1" <?= $inv['wants'] ? 'checked' : '' ?>> Klient chce fakturę</label>
        <div class="inv-grid">
            <label for="invNip">NIP:</label>
            <div class="inv-nip">
                <input id="invNip" name="nip" value="<?= htmlspecialchars($inv['nip']) ?>" inputmode="numeric" placeholder="np. 123-456-32-18">
                <div class="inv-gus">
                    <button type="button" class="btn secondary" id="invGusBtn" onclick="invLookup('auto')" title="Pobierz dane firmy po NIP">🔍 GUS</button>
                    <button type="button" class="btn secondary inv-gus-more" onclick="invMenu()" aria-label="Więcej opcji">▾</button>
                    <div class="inv-menu" id="invMenu" hidden>
                        <button type="button" onclick="invLookup('gus')">Szukaj w GUS (REGON)</button>
                        <button type="button" onclick="invLookup('mf')">Szukaj na Białej liście VAT</button>
                        <?php if (isAdmin()): ?><button type="button" onclick="invKey()">⚙ Klucz API GUS…</button><?php endif; ?>
                    </div>
                </div>
            </div>
            <div id="invMsg" class="inv-msg" aria-live="polite"></div>
            <label for="invName">Imię i nazwisko:</label><input id="invName" name="name" value="<?= htmlspecialchars($inv['name']) ?>">
            <label for="invCompany">Firma:</label><input id="invCompany" name="company" value="<?= htmlspecialchars($inv['company']) ?>">
            <label for="invAddr">Adres:</label><input id="invAddr" name="address_1" value="<?= htmlspecialchars($inv['address_1']) ?>">
            <label for="invPost">Kod pocztowy:</label><input id="invPost" name="postcode" value="<?= htmlspecialchars($inv['postcode']) ?>">
            <label for="invCity">Miasto:</label><input id="invCity" name="city" value="<?= htmlspecialchars($inv['city']) ?>">
            <label for="invState">Województwo:</label><input id="invState" name="state" list="invStates" value="<?= htmlspecialchars($inv['state']) ?>">
            <label for="invCountry">Kraj:</label>
            <select id="invCountry" name="country">
                <?php foreach ($invCountries as $cc => $cn): ?><option value="<?= htmlspecialchars($cc) ?>" <?= $cc === $inv['country'] ? 'selected' : '' ?>><?= htmlspecialchars($cn) ?></option><?php endforeach; ?>
            </select>
        </div>
        <datalist id="invStates"><?php foreach (['dolnośląskie','kujawsko-pomorskie','lubelskie','lubuskie','łódzkie','małopolskie','mazowieckie','opolskie','podkarpackie','podlaskie','pomorskie','śląskie','świętokrzyskie','warmińsko-mazurskie','wielkopolskie','zachodniopomorskie'] as $st): ?><option value="<?= $st ?>"><?php endforeach; ?></datalist>
        <p class="inv-actions">
            <button class="btn" type="submit">Zapisz</button>
            <button class="btn secondary" type="button" onclick="invToggle(false)">Anuluj</button>
            <?php if ($inv['edited']): ?><button class="btn secondary" type="submit" name="action" value="restore_invoice_data" formnovalidate title="Usuń poprawkę i pokaż dane ze sklepu">↺ Dane ze sklepu</button><?php endif; ?>
        </p>
        <p class="inv-hint">Zmiana zapisuje się tylko w CRM: faktura w wFirma, wydruki i lista zamówień biorą te dane. Sklep i klient widzą swoją wersję.</p>
    </form>
    <?php endif; ?>
</div>

<?php $renderExtSections($extSections['right']); ?>

</div><!-- /layout-col right -->
</div><!-- /layout-cols -->

<style>
    .invoice-alert { display:flex; align-items:center; gap:14px; flex-wrap:wrap; margin:0 0 14px; padding:14px 18px; border-radius:12px; background:#fff1d6; color:#7a4500; border:1px solid #f3c56b; }
    .invoice-alert strong { font-size:16px; display:block; margin-bottom:2px; }
    .note-alert { display:flex; align-items:flex-start; gap:14px; flex-wrap:wrap; margin:0 0 14px; padding:14px 18px; border-radius:12px; background:#fdf6d3; color:#5c4400; border:1px solid #e9c94a; }
    .note-alert strong { font-size:16px; display:block; margin-bottom:4px; }
    .note-alert .note-text { font-size:15px; line-height:1.45; white-space:normal; overflow-wrap:anywhere; }
    .note-alert .note-text + .note-from { margin-top:8px; }
    .note-alert .note-from { font-size:13px; opacity:.85; }
    .note-alert a { color:inherit; text-decoration:underline; text-underline-offset:3px; }
    .merge-alert { display:flex; align-items:center; gap:14px; flex-wrap:wrap; margin:0 0 14px; padding:12px 18px; border-radius:12px; background:#eef0f6; color:#374151; border:1px solid #d5d9e4; }
    .merge-alert strong { font-size:15px; }
    .merge-alert a { color:inherit; text-decoration:underline; text-underline-offset:3px; }
    .merge-alert a:hover { color:var(--accent); }
    tr.merged-head td { background:var(--surface-2); font-size:13px; }
    tr.merged-head a { color:inherit; text-decoration:underline; text-decoration-color:var(--line); text-underline-offset:3px; }
    tr.merged-head a:hover { color:var(--accent); }
    .receipt-state { display:inline-flex; align-items:center; gap:6px; margin-left:10px; padding:3px 10px; border-radius:999px; font-size:12px; font-weight:600; vertical-align:middle; }
    .receipt-state.ok { background:#e6f4ea; color:#137333; }
    a.receipt-state { text-decoration:none; }
    a.receipt-state:hover { text-decoration:underline; text-underline-offset:3px; }
    .receipt-state.warn { background:#fff1d6; color:#975500; }
    .receipt-link { margin-left:8px; font-size:12px; color:var(--ink-2); text-decoration:underline; text-underline-offset:3px; }
    .receipt-link:hover { color:var(--accent); }
    /* Wątek wiadomości: stała wysokość z przewijaniem (najnowsze na dole), długie wiadomości zwinięte. */
    .msg-thread { display:flex; flex-direction:column; gap:8px; margin:10px 0; max-height:460px; overflow-y:auto; overscroll-behavior:contain; padding:8px; border:1px solid #e7ebf0; border-radius:10px; background:#fbfcfd; }
    .msg-thread:has(> p:only-child) { border:0; background:none; padding:0; }
    .msg-bubble { max-width:80%; border-radius:10px; padding:7px 11px; flex:0 0 auto; }
    .msg-bubble.is-staff { align-self:flex-end; background:#e8f0fe; }
    .msg-bubble.is-client { align-self:flex-start; background:#f1f3f5; }
    .msg-bubble.is-auto { align-self:flex-end; background:#fbf6ea; border:1px dashed #e0cfa6; }
    .msg-auto-tag { display:inline-block; font-size:10px; font-weight:600; color:#8a6a1f; background:#f3e7c7; border-radius:6px; padding:0 6px; margin-right:4px; }
    .msg-body { font-size:13.5px; line-height:1.45; word-break:break-word; }
    .msg-body.is-clamped { max-height:7.5em; overflow:hidden; -webkit-mask-image:linear-gradient(#000 65%, transparent); mask-image:linear-gradient(#000 65%, transparent); }
    .msg-body a { color:var(--accent, #1a73e8); text-decoration:underline; text-decoration-color:rgba(0,0,0,.25); text-underline-offset:2px; word-break:break-all; }
    .msg-more { background:none; border:0; padding:2px 0 0; color:#1a73e8; font-size:12px; cursor:pointer; }
    .staff-msg-del { background:none; border:0; color:#c5221f; font-size:11px; cursor:pointer; padding:0 0 0 4px; text-decoration:underline; }
    .staff-msg-del:hover { color:#a01a16; }
    .layout-cols { display:grid; grid-template-columns:1fr 1fr; gap:18px; align-items:start; }
    .layout-col { display:flex; flex-direction:column; gap:18px; min-height:60px; }
    .sortable-section { position:relative; }
    .drag-handle { position:absolute; top:10px; right:12px; color:#cbd3e1; cursor:grab; font-size:18px; line-height:1; user-select:none; }
    .drag-handle:active { cursor:grabbing; }
    .sortable-ghost { opacity:.4; }
    .sortable-drag { box-shadow:0 12px 32px rgba(0,0,0,.2); }
    @media (max-width:900px) { .layout-cols { grid-template-columns:1fr; } }
    .soon-menu { position:relative; display:inline-block; }
    .soon-dropdown { display:none; position:absolute; top:100%; right:0; margin-top:4px; background:#fff; border-radius:8px; box-shadow:0 8px 28px rgba(0,0,0,.16); padding:6px; min-width:330px; z-index:50; }
    .soon-menu.open .soon-dropdown { display:block; }
    .soon-dropdown .soon-item { display:block; width:100%; text-align:left; background:none; border:0; padding:9px 12px; border-radius:6px; cursor:pointer; font-size:14px; color:#2c3e50; }
    .soon-dropdown .soon-item:hover { background:#f0f4ff; }
    /* Wiersz w menu druku: po lewej podgląd (nazwa szablonu), po prawej druk na Zebrze. */
    .soon-dropdown .soon-row { display:flex; align-items:center; gap:4px; flex-wrap:wrap; }
    .soon-dropdown .soon-agent { flex:none; padding:6px 9px; border-radius:6px; font-size:12px; font-weight:600; white-space:nowrap; text-decoration:none; color:#7a5220; background:#f6efe4; }
    .soon-dropdown .soon-agent:hover { background:#e9dcc6; }
    .soon-dropdown .soon-agent.busy { opacity:.6; pointer-events:none; }
    .inline-edit-field { width:100%; border:0; background:transparent; padding:0; margin:0; font:inherit; color:inherit; }
    .inline-edit-field:disabled { opacity:1; -webkit-text-fill-color:currentColor; cursor:default; }
    .inline-edit-field:not(:disabled) { border:1px solid var(--line); border-radius:6px; padding:6px 9px; background:var(--surface); }
    .inline-edit-field:not(:disabled):focus { outline:none; border-color:var(--accent); box-shadow:0 0 0 3px var(--accent-soft); }
</style>
<script>
(function () {
    // Przyciski-placeholdery: pokaż komunikat "w przygotowaniu".
    document.addEventListener('click', function (e) {
        var item = e.target.closest('.soon-item');
        if (item && item.dataset.soon) {
            e.preventDefault();
            alert(item.dataset.soon + ' — funkcja w przygotowaniu.\nBędzie dostępna po podłączeniu integracji.');
            var menu = item.closest('.soon-menu');
            if (menu) menu.classList.remove('open');
            return;
        }
        // Zamknij menu Drukuj/Eksportuj po kliknięciu poza nim.
        if (!e.target.closest('.soon-menu')) {
            document.querySelectorAll('.soon-menu.open').forEach(function (m) { m.classList.remove('open'); });
        }
    });

    // Odblokowanie pól E-mail/Telefon "w miejscu" (Informacje o zamówieniu).
    var editBtn = document.getElementById('contactEditBtn');
    var cancelBtn = document.getElementById('contactCancelBtn');
    var actions = document.getElementById('contactActions');
    var emailInput = document.getElementById('contactEmailInput');
    var phoneInput = document.getElementById('contactPhoneInput');
    if (editBtn && emailInput && phoneInput && actions) {
        var initial = { email: emailInput.value, phone: phoneInput.value };
        editBtn.addEventListener('click', function () {
            emailInput.disabled = false;
            phoneInput.disabled = false;
            actions.hidden = false;
            editBtn.hidden = true;
            emailInput.focus();
        });
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                emailInput.value = initial.email;
                phoneInput.value = initial.phone;
                emailInput.disabled = true;
                phoneInput.disabled = true;
                actions.hidden = true;
                editBtn.hidden = false;
            });
        }
    }
})();
</script>

<!-- Drag & drop sekcji (SortableJS z CDN) -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js" integrity="sha384-BSxuMLxX+FCbTdYec3TbXlnMGEEM2QXTFdtDaveen71o+jswm2J36+xFqp8k4VHM" crossorigin="anonymous"></script>
<script>
(function () {
    var SAVED = <?= json_encode($savedLayout ?: null, JSON_UNESCAPED_UNICODE) ?>;
    var CSRF  = <?= json_encode(csrfToken()) ?>;
    <?php if (!canEdit()): ?>return;<?php endif; ?>

    var cols = {
        left:  document.querySelector('.layout-col[data-col="left"]'),
        right: document.querySelector('.layout-col[data-col="right"]')
    };
    if (!cols.left || !cols.right || typeof Sortable === 'undefined') return;

    // Mapa sekcji po id (do odtworzenia zapisanego układu).
    var byId = {};
    document.querySelectorAll('.sortable-section').forEach(function (el) {
        byId[el.dataset.section] = el;
    });

    // Odtwórz zapisany układ (jeśli jest) - przenieś karty do właściwych kolumn/kolejności.
    if (SAVED && SAVED.left && SAVED.right) {
        ['left', 'right'].forEach(function (side) {
            SAVED[side].forEach(function (id) {
                if (byId[id]) cols[side].appendChild(byId[id]);
            });
        });
    }

    function currentLayout() {
        function ids(col) {
            return Array.prototype.map.call(col.querySelectorAll('.sortable-section'), function (e) { return e.dataset.section; });
        }
        return { left: ids(cols.left), right: ids(cols.right) };
    }

    function save() {
        fetch('layout_save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF },
            body: JSON.stringify({ view: 'order_view', layout: currentLayout() })
        }).catch(function () {});
    }

    var opts = {
        group: 'orderSections',
        handle: '.drag-handle',
        animation: 150,
        ghostClass: 'sortable-ghost',
        dragClass: 'sortable-drag',
        onEnd: save
    };
    Sortable.create(cols.left, opts);
    Sortable.create(cols.right, opts);
})();
</script>

<!-- Nadawanie przesyłki BLPaczka (wycena / nadanie) -->
<script>
(function () {
    var box = document.getElementById('shipForm');
    if (!box) return;
    var CSRF = <?= json_encode(csrfToken()) ?>;
    var msg = document.getElementById('shipMsg');

    // Przełącznik sposobu nadania: pokaż/ukryj pola odbioru kuriera.
    var pickupFields = document.getElementById('pickupFields');
    function pickupMode() {
        var sel = box.querySelector('input[name="sp_pickup_mode"]:checked');
        return sel ? sel.value : 'courier';
    }
    box.querySelectorAll('input[name="sp_pickup_mode"]').forEach(function (r) {
        r.addEventListener('change', function () {
            if (pickupFields) pickupFields.style.display = (pickupMode() === 'self') ? 'none' : 'grid';
        });
    });

    function params() {
        return new URLSearchParams({
            csrf: CSRF,
            woo_order_id: box.dataset.order,
            weight: document.getElementById('sp_weight').value,
            side_x: document.getElementById('sp_x').value,
            side_y: document.getElementById('sp_y').value,
            side_z: document.getElementById('sp_z').value,
            courier_code: document.getElementById('sp_courier').value,
            pickup_mode: pickupMode(),
            pickup_date: document.getElementById('sp_pickup_date').value,
            pickup_from: document.getElementById('sp_pickup_from').value,
            pickup_to: document.getElementById('sp_pickup_to').value,
            point_id: (document.getElementById('sp_point') || {value: ''}).value
        });
    }
    function call(action, onok) {
        var p = params(); p.append('action', action);
        msg.textContent = '⏳ ...'; msg.style.color = '#888';
        fetch('shipment_create.php', { method: 'POST', body: p })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                msg.textContent = (d.ok ? '✅ ' : '❌ ') + (d.message || '');
                msg.style.color = d.ok ? '#137333' : '#c5221f';
                var hint = document.getElementById('shipHint');
                if (hint) { hint.textContent = d.hint ? 'ℹ️ ' + d.hint : ''; hint.style.display = d.hint ? 'block' : 'none'; }
                if (d.account) showBalance(d.account);
                // Pokaż surową odpowiedź API gdy błąd LUB gdy wycena bez ceny (?).
                var dbg = document.getElementById('shipDebug');
                var showRaw = (!d.ok || (action === 'quote' && (d.price === null || d.price === undefined)));
                if (showRaw && d.raw !== undefined && dbg) {
                    dbg.style.display = 'block';
                    dbg.textContent = JSON.stringify(d.raw, null, 2);
                } else if (dbg) { dbg.style.display = 'none'; }
                if (d.ok && onok) onok(d);
            })
            .catch(function () { msg.textContent = '❌ Błąd połączenia.'; msg.style.color = '#c5221f'; });
    }

    // Saldo skarbonki BLPaczka + forma płatności i środowisko - to samo, co widzi BLPaczka przy nadaniu.
    var balEl = document.getElementById('shipBalance');
    function showBalance(a) {
        if (!balEl) return;
        var envTxt = a.env === 'production' ? 'Produkcja' : 'Sandbox (konto testowe!)';
        var bal = (a.balance !== null && a.balance !== undefined)
            ? Number(a.balance).toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' zł'
            : 'nie udało się odczytać' + (a.balance_error ? ' (' + a.balance_error + ')' : '');
        balEl.textContent = 'Skarbonka BLPaczka' + (a.login ? ' (' + a.login + ')' : '') + ': ' + bal
            + ' · środowisko: ' + envTxt + ' · płatność za nadanie: ' + (a.payment_label || a.payment)
            + (a.payment === 'bank' ? ' - opłata schodzi ze skarbonki.' : '.');
        balEl.style.color = a.env !== 'production' ? '#b45309' : '#888';
    }
    fetch('shipment_create.php', { method: 'POST', body: new URLSearchParams({ csrf: CSRF, action: 'balance', woo_order_id: box.dataset.order }) })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (d.ok) showBalance(d); else if (balEl) balEl.textContent = 'Saldo BLPaczka: ' + (d.message || 'brak danych'); })
        .catch(function () { if (balEl) balEl.textContent = 'Saldo BLPaczka: błąd połączenia.'; });

    // Wypełnij dropdown kurierów listą z API (mapa kod -> nazwa).
    var sel = document.getElementById('sp_courier');
    var favBox = document.getElementById('favCourierBtns');
    var favCodes = JSON.parse(favBox.dataset.fav || '[]');

    function highlightFav() {
        favBox.querySelectorAll('button').forEach(function (b) {
            b.style.background = (b.dataset.code === sel.value) ? '#667eea' : '#eef0f6';
            b.style.color = (b.dataset.code === sel.value) ? '#fff' : '#2c3e50';
        });
    }
    function renderFav(available, names) {
        favBox.innerHTML = '';
        favCodes.forEach(function (code) {
            if (available.indexOf(code) === -1) return; // tylko dostępni dla tej paczki
            var b = document.createElement('button');
            b.type = 'button'; b.dataset.code = code;
            b.textContent = names[code] || code;
            b.style.cssText = 'border:0;border-radius:7px;padding:6px 12px;font-size:13px;font-weight:600;cursor:pointer;background:#eef0f6;color:#2c3e50';
            b.addEventListener('click', function () { sel.value = code; highlightFav(); });
            favBox.appendChild(b);
        });
    }

    function loadCouriers() {
        var p = params(); p.append('action', 'couriers');
        fetch('shipment_create.php', { method: 'POST', body: p })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var raw = d.raw || {};
                var available = raw.availableCouriers || [];
                var names = raw.courierNames || {};
                if (!available.length) {
                    sel.innerHTML = '<option value="">brak kurierów dla podanej paczki</option>';
                    favBox.innerHTML = '';
                    return;
                }
                sel.innerHTML = '<option value="">— wybierz kuriera —</option>';
                available.forEach(function (code) {
                    var o = document.createElement('option');
                    o.value = code; o.textContent = names[code] || code;
                    sel.appendChild(o);
                });
                renderFav(available, names);
                highlightFav();
            })
            .catch(function () { sel.innerHTML = '<option value="">błąd ładowania kurierów</option>'; });
    }
    sel.addEventListener('change', highlightFav);
    loadCouriers();
    // Przeładuj listę po zmianie wymiarów (różni kurierzy dla różnych paczek).
    ['sp_weight','sp_x','sp_y','sp_z'].forEach(function (id) {
        document.getElementById(id).addEventListener('change', loadCouriers);
    });

    document.getElementById('btnSend').addEventListener('click', function () {
        if (!confirm('Nadać przesyłkę? BLPaczka pobierze opłatę za nadanie.')) return;
        call('send', function () { setTimeout(function () { location.reload(); }, 1200); });
    });
})();
</script>

<!-- Wybór przewoźnika („Nadaj przez") + panele wtyczek kurierskich (courier_shipment.php) -->
<style>
.ship-tabs{display:flex;gap:6px;flex-wrap:wrap}
.ship-tab{border:1.5px solid var(--acc,#8a6a3b);background:#fff;border-radius:8px;padding:7px 14px;font-size:14px;font-weight:600;color:var(--acc,#8a6a3b);cursor:pointer;transition:background .15s,color .15s}
.ship-tab:hover{background:color-mix(in srgb,var(--acc,#8a6a3b) 8%,#fff)}
.ship-tab.active{background:var(--acc,#8a6a3b);color:#fff}
.ship-pane{margin-top:6px;padding-top:12px;border-top:1px solid #eee}
.ship-pane[hidden]{display:none}
</style>
<script>
(function () {
    var CSRF = <?= json_encode(csrfToken()) ?>;
    var loaded = {};

    var current = null;
    function show(key) {
        key = key === current ? null : key;   // drugie kliknięcie zwija formularz
        current = key;
        document.querySelectorAll('.ship-tab').forEach(function (x) { x.classList.toggle('active', x.dataset.tab === key); });
        document.querySelectorAll('.ship-pane').forEach(function (p) {
            p.hidden = p.dataset.pane !== key;
            if (!p.hidden && p.classList.contains('courier-pane')) load(p);
        });
    }
    document.querySelectorAll('.ship-tab').forEach(function (t) {
        t.addEventListener('click', function () { show(t.dataset.tab); });
    });

    function post(pane, params) {
        params.append('csrf', CSRF);
        params.append('integration', pane.dataset.integration);
        params.append('woo_order_id', pane.dataset.order);
        return fetch('courier_shipment.php', { method: 'POST', body: params }).then(function (r) { return r.json(); });
    }

    var GROUPS = { allegro: 'Umowa Allegro', own: 'Własna umowa z przewoźnikiem' };

    // Umowy/usługi + podpowiedź paczki - ładowane przy pierwszym pokazaniu panelu.
    function load(pane) {
        if (loaded[pane.dataset.pane]) return;
        loaded[pane.dataset.pane] = true;
        var info = pane.querySelector('.cp-info');
        var sel = pane.querySelector('.cp-service');
        post(pane, new URLSearchParams({ action: 'services' })).then(function (d) {
            if (!d.ok) { info.textContent = '⚠️ ' + (d.message || 'Nie udało się pobrać danych.'); info.style.color = '#b06000'; sel.innerHTML = ''; return; }
            var sv = d.services || {};
            sel.innerHTML = '';
            var groups = {};
            (sv.services || []).forEach(function (s) {
                var g = s.group || 'other';
                if (!groups[g]) {
                    groups[g] = document.createElement('optgroup');
                    groups[g].label = GROUPS[g] || (sv.groups || {})[g] || 'Usługi';
                    sel.appendChild(groups[g]);
                }
                var o = document.createElement('option');
                o.value = s.id; o.textContent = s.label;
                groups[g].appendChild(o);
            });
            if (sv.default) sel.value = sv.default;

            // Sposób nadania (radio) + dzień odbioru przy kurierze.
            var ho = sv.handover;
            var hoRow = pane.querySelector('.cp-handover-row');
            if (ho && ho.options) {
                var box = pane.querySelector('.cp-handover');
                box.innerHTML = '';
                Object.keys(ho.options).forEach(function (k) {
                    var l = document.createElement('label');
                    l.style.cssText = 'font-size:13px;cursor:pointer';
                    var r = document.createElement('input');
                    r.type = 'radio'; r.name = 'cp-ho-' + pane.dataset.integration; r.value = k; r.checked = k === ho.default;
                    r.addEventListener('change', function () { syncPickup(pane); quote(pane); });
                    l.appendChild(r); l.appendChild(document.createTextNode(' ' + ho.options[k]));
                    box.appendChild(l);
                });
                hoRow.style.display = '';
                syncPickup(pane);
            }
            // Punkt odbioru (np. ORLEN Paczka): wartość z zamówienia + wyszukiwarka punktów.
            var pt = sv.point;
            if (pt) {
                var prow = pane.querySelector('.cp-point-row');
                var pin = pane.querySelector('.cp-point');
                pane.querySelector('.cp-point-label').textContent = pt.label || 'Punkt odbioru';
                pin.value = pt.value || '';
                pin.placeholder = pt.placeholder || '';
                pin.dataset.required = pt.required ? '1' : '';
                pane.querySelector('.cp-point-note').textContent = pt.note || '';
                prow.style.display = '';
                if (pt.search) pointSearch(pane);
            }
            var parts = [];
            var pr = d.proposal;
            if (pr && pr.ok) {
                var pk = pr.package || {};
                [['weight','.cp-weight'],['x','.cp-x'],['y','.cp-y'],['z','.cp-z']].forEach(function (m) {
                    if (pk[m[0]]) pane.querySelector(m[1]).value = pk[m[0]];
                });
                if (pr.sender) parts.push('Nadawca: ' + pr.sender);
                if (pr.message) parts.push(pr.message);
            } else if (pr && pr.message) {
                parts.push('⚠️ ' + pr.message);
            }
            if (sv.message) parts.push('⚠️ ' + sv.message);
            if ((sv.services || []).some(function (s) { return s.group === 'allegro'; }) && !(sv.services || []).some(function (s) { return s.group === 'own'; })) {
                parts.push('Brak własnych umów podpiętych w Allegro — dostępna umowa Allegro.');
            }
            info.textContent = parts.join(' · ');
            info.style.color = '#888';
            quote(pane);
        }).catch(function () { info.textContent = '⚠️ Błąd połączenia.'; info.style.color = '#b06000'; });
    }

    var manual = document.getElementById('manualShip');
    if (manual) {
        document.getElementById('btnManualShip').addEventListener('click', function () {
            var m = document.getElementById('msMsg');
            var p = new URLSearchParams({ csrf: CSRF, action: 'manual', woo_order_id: manual.dataset.order,
                carrier: document.getElementById('ms_carrier').value, waybill: document.getElementById('ms_waybill').value });
            fetch('courier_shipment.php', { method: 'POST', body: p }).then(function (r) { return r.json(); }).then(function (d) {
                m.textContent = (d.ok ? '✅ ' : '❌ ') + (d.message || ''); m.style.color = d.ok ? '#137333' : '#c5221f';
                if (d.ok) setTimeout(function () { location.reload(); }, 800);
            }).catch(function () { m.textContent = '❌ Błąd połączenia.'; m.style.color = '#c5221f'; });
        });
    }

    // Podpowiedzi punktów po wpisaniu kodu, numeru, miasta albo kodu pocztowego.
    function pointSearch(pane) {
        var pin = pane.querySelector('.cp-point'), list = pane.querySelector('datalist'), note = pane.querySelector('.cp-point-note');
        var timer = null, labels = {};
        pin.addEventListener('input', function () {
            var v = pin.value.trim();
            if (labels[v]) { note.textContent = labels[v]; return; }
            clearTimeout(timer);
            if (v.length < 2 || /^[A-Za-z]{2}-\d{6}-\w{2}-\w{2}$/.test(v)) return;
            timer = setTimeout(function () {
                post(pane, new URLSearchParams({ action: 'points', q: v })).then(function (d) {
                    list.innerHTML = '';
                    (d.points || []).forEach(function (p) {
                        var o = document.createElement('option');
                        o.value = p.code; o.textContent = p.label; o.label = p.label;
                        labels[p.code] = p.label;
                        list.appendChild(o);
                    });
                    if (!d.ok || (d.points || []).length === 0) note.textContent = d.message || '';
                }).catch(function () {});
            }, 300);
        });
    }

    function handover(pane) {
        var r = pane.querySelector('.cp-handover input:checked');
        return r ? r.value : '';
    }
    function syncPickup(pane) {
        pane.querySelector('.cp-pickup').style.display = handover(pane) === 'courier' ? '' : 'none';
    }
    function serviceLabel(pane) {
        var o = pane.querySelector('.cp-service').selectedOptions[0];
        return o ? o.textContent : '';
    }
    // Wycena przed nadaniem (cennik wtyczki / ostatni faktyczny koszt tą metodą).
    var quoteTimer = {};
    function quote(pane) {
        clearTimeout(quoteTimer[pane.dataset.pane]);
        quoteTimer[pane.dataset.pane] = setTimeout(function () {
            var price = pane.querySelector('.cp-price'), src = pane.querySelector('.cp-price-src');
            price.textContent = '…'; src.textContent = '';
            function q(c) { return pane.querySelector(c).value; }
            post(pane, new URLSearchParams({ action: 'quote', service: q('.cp-service'), service_label: serviceLabel(pane),
                handover: handover(pane), weight: q('.cp-weight'), side_x: q('.cp-x'), side_y: q('.cp-y'), side_z: q('.cp-z') }))
                .then(function (d) {
                    pane.dataset.quoted = d.ok ? d.price : '';
                    price.textContent = d.ok ? d.price.toFixed(2).replace('.', ',') + ' zł' : '—';
                    src.textContent = d.ok ? '(' + d.source + ')' : (d.message || '');
                }).catch(function () { price.textContent = '—'; });
        }, 250);
    }

    document.querySelectorAll('.courier-pane').forEach(function (pane) {
        if (!pane.hidden) load(pane);
        ['.cp-service', '.cp-weight', '.cp-x', '.cp-y', '.cp-z'].forEach(function (c) {
            pane.querySelector(c).addEventListener('change', function () { quote(pane); });
        });
        var btn = pane.querySelector('.cp-send');
        var msg = pane.querySelector('.cp-msg');
        btn.addEventListener('click', function () {
            var svc = pane.querySelector('.cp-service');
            var svcName = svc.selectedOptions[0] ? svc.selectedOptions[0].textContent : '';
            var hoLbl = pane.querySelector('.cp-handover input:checked');
            hoLbl = hoLbl ? '\n' + hoLbl.parentElement.textContent.trim() : '';
            var cost = pane.querySelector('.cp-price').textContent;
            var pin = pane.querySelector('.cp-point');
            var ptLbl = '';
            if (pane.querySelector('.cp-point-row').style.display !== 'none') {
                if (pin.dataset.required && pin.value.trim() === '') {
                    msg.textContent = '❌ Podaj punkt odbioru.'; msg.style.color = '#c5221f'; pin.focus(); return;
                }
                ptLbl = pin.value.trim() ? '\nPunkt: ' + pin.value.trim() : '';
            }
            if (!confirm('Nadać przesyłkę: ' + svcName + hoLbl + ptLbl + (cost && cost !== '—' ? '\nSzacowany koszt: ' + cost : '') + '?')) return;
            btn.disabled = true;
            msg.textContent = '⏳ Nadaję (może potrwać kilkanaście sekund)…'; msg.style.color = '#888';
            function q(c) { return pane.querySelector(c).value; }
            post(pane, new URLSearchParams({
                action: 'send', service: svc.value, weight: q('.cp-weight'), side_x: q('.cp-x'), side_y: q('.cp-y'), side_z: q('.cp-z'),
                label_format: q('.cp-label'), text_on_label: q('.cp-text'),
                handover: handover(pane), pickup_date: q('.cp-pickup-date'), point: q('.cp-point'),
                service_label: serviceLabel(pane), quoted_price: pane.dataset.quoted || ''
            })).then(function (d) {
                msg.textContent = (d.ok ? (d.pending ? '⏳ ' : '✅ ') : '❌ ') + (d.message || '');
                msg.style.color = d.ok ? (d.pending ? '#9c6b2e' : '#137333') : '#c5221f';
                if (d.ok) setTimeout(function () { location.reload(); }, 1500);
                else btn.disabled = false;
            }).catch(function () { msg.textContent = '❌ Błąd połączenia.'; msg.style.color = '#c5221f'; btn.disabled = false; });
        });
    });
})();
</script>

<!-- Menu etykiety: jeden przycisk, opcje po kliknięciu -->
<style>
.track-ship{border:0;background:none;cursor:pointer;color:#888;font-size:14px;padding:0 4px;vertical-align:middle}
.track-ship:hover{color:#333}
.track-ship.spin{animation:trkspin .8s linear infinite}
@keyframes trkspin{to{transform:rotate(360deg)}}
.trk{position:relative;display:inline-block}
.trk-pill{border:0;cursor:pointer;font:inherit;font-size:12px;line-height:inherit;white-space:nowrap}
.trk + .track-ship{margin-left:2px}
.ship-status-set{border:0;background:none;cursor:pointer;color:#888;font-size:13px;padding:0 2px;width:26px;vertical-align:middle;appearance:none;-webkit-appearance:none;text-align:center}
.ship-status-set:hover{color:#333}
.trk-pop{display:none;position:absolute;left:0;top:calc(100% + 6px);z-index:30;min-width:260px;max-width:340px;background:#fff;border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.12);padding:10px 12px;font-size:12px;color:#444;text-align:left;white-space:normal}
.trk:hover .trk-pop,.trk.open .trk-pop{display:block}
.trk-pop::before{content:'';position:absolute;left:0;right:0;top:-8px;height:8px}
.trk-head{display:block;font-weight:600;color:#333;margin-bottom:6px}
.trk-pop ul{margin:0;padding-left:16px}
.trk-pop li{margin:3px 0}
.trk-pop li span{color:#999;margin-right:4px}
.trk-note{display:block;color:#666}
.trk-checked{display:block;color:#aaa;margin-top:6px;font-size:11px}
.pp-box{border:1px solid #eef0f3;border-radius:8px;padding:10px 12px;margin:0 0 16px}
.pp-table{margin-top:6px;font-size:13px}
.pp-table th{width:110px;text-align:left;color:#888;font-weight:500;padding:3px 8px 3px 0;border:0;text-transform:none;letter-spacing:0;font-size:13px}
.pp-table td{padding:3px 0;border:0}
.pp-edit{margin-top:10px;display:grid;gap:6px;max-width:420px}
.pp-edit[hidden]{display:none}
.pp-edit label{font-size:11px;color:#888;display:block}
.pp-edit input{width:100%}
.inv-head{display:flex;align-items:center;gap:8px}
.inv-head .inv-edit-btn{margin-left:auto;padding:3px 9px;font-size:12px}
.inv-note{margin:6px 0 0;color:#8a6d1f;font-size:13px}
.inv-edit{margin-top:10px;max-width:460px}
.inv-edit[hidden],#invShow[hidden]{display:none}
.inv-check{display:flex;align-items:center;gap:8px;margin:0 0 10px;font-size:14px;cursor:pointer}
.inv-grid{display:grid;grid-template-columns:auto 1fr;gap:7px 10px;align-items:center}
.inv-grid > label{font-size:13px;color:var(--ink-2,#555);white-space:nowrap}
.inv-grid input,.inv-grid select{width:100%;min-width:0;box-sizing:border-box}
.inv-nip{display:flex;gap:6px;min-width:0}
.inv-nip input{flex:1}
.inv-gus{position:relative;display:flex;flex-shrink:0}
.inv-gus .btn{padding:5px 10px;font-size:13px;border-radius:8px 0 0 8px;white-space:nowrap}
.inv-gus .inv-gus-more{border-radius:0 8px 8px 0;border-left:0;padding:5px 8px}
.inv-menu{position:absolute;right:0;top:calc(100% + 4px);z-index:20;background:#fff;border:1px solid #e3e0d8;border-radius:8px;box-shadow:0 6px 20px rgba(0,0,0,.12);padding:4px;min-width:220px}
.inv-menu[hidden]{display:none}
.inv-menu button{display:block;width:100%;text-align:left;background:none;border:0;padding:8px 10px;border-radius:6px;font-size:13px;cursor:pointer;color:inherit}
.inv-menu button:hover{background:#f3f1ec}
.inv-msg{font-size:12px;min-height:0;color:#666}
.inv-msg:empty{display:none}
.inv-grid .inv-msg{grid-column:2}
.inv-msg.ok{color:#2f7a4f}.inv-msg.bad{color:#a3341f}
.inv-grid input.inv-filled{background:#f0f8f2;transition:background 1.5s}
.inv-actions{display:flex;gap:6px;flex-wrap:wrap;margin:12px 0 0}
.inv-hint{font-size:11px;color:#888;margin:8px 0 0}
@media (max-width:520px){.inv-grid{grid-template-columns:1fr}.inv-grid .inv-msg{grid-column:auto}}
.waybill-link{color:inherit;text-decoration:none;white-space:nowrap}
.waybill-link:hover strong{color:#1a73e8;text-decoration:underline}
.lbl-menu{position:relative;display:inline-block}
.lbl-pop{position:absolute;right:0;top:calc(100% + 6px);z-index:50;min-width:250px;background:#fff;border:1px solid #e5e2da;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.12);padding:10px;display:flex;flex-direction:column;gap:2px;text-align:left}
.lbl-pop[hidden]{display:none}
.lbl-pop-title{font-size:11px;color:#888;margin:0 2px 2px}
.lbl-pop select{width:100%;font-size:13px;padding:5px 6px;margin-bottom:6px}
.lbl-item{display:block;padding:7px 8px;border-radius:6px;font-size:13px;color:#2c2a26;text-decoration:none;white-space:nowrap}
.lbl-item:hover{background:#f5f3ee}
</style>
<script>
(function () {
    function closeAll(except) {
        document.querySelectorAll('.lbl-menu').forEach(function (m) {
            if (m === except) return;
            m.querySelector('.lbl-pop').hidden = true;
            m.querySelector('.lbl-toggle').setAttribute('aria-expanded', 'false');
        });
    }
    document.querySelectorAll('.lbl-menu').forEach(function (m) {
        var btn = m.querySelector('.lbl-toggle'), pop = m.querySelector('.lbl-pop');
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            closeAll(m);
            pop.hidden = !pop.hidden;
            btn.setAttribute('aria-expanded', pop.hidden ? 'false' : 'true');
        });
        pop.addEventListener('click', function (e) {
            e.stopPropagation();
            if (e.target.closest('.lbl-item')) setTimeout(function () { closeAll(null); }, 0); // po akcji zwiń
        });
    });
    document.addEventListener('click', function () { closeAll(null); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAll(null); });
})();
</script>

<!-- Etykiety: druk / otwarcie w wybranym formacie -->
<script>
(function () {
    function fmtFor(ship) {
        var sel = document.querySelector('.lbl-fmt[data-ship="' + ship + '"]');
        return sel ? sel.value : 'A4';
    }
    document.querySelectorAll('.lbl-print').forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            var s = a.dataset.ship;
            window.open('label_download.php?shipment=' + s + '&printer=' + fmtFor(s) + '&print=1', '_blank');
        });
    });
    document.querySelectorAll('.lbl-open').forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            var s = a.dataset.ship;
            window.open('label_download.php?shipment=' + s + '&printer=' + fmtFor(s), '_blank');
        });
    });
    document.querySelectorAll('.lbl-agent').forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            var s = a.dataset.ship;
            // Każdy format idzie do agenta: ZPL/EPL surowo, PDF/A4/LBL agent
            // zrasteryzuje u siebie i wyśle na Zebrę jako grafikę.
            var fmt = fmtFor(s);
            var label = a.dataset.label || (a.dataset.label = a.textContent);
            a.textContent = '⏳ wysyłam…';
            fetch('label_download.php?shipment=' + s + '&printer=' + fmt + '&agent=1', {
                method: 'POST', body: new URLSearchParams({csrf: <?= json_encode(csrfToken()) ?>})
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.ok) {
                        // Bez okienka: potwierdzenie na chwilę w samym przycisku.
                        var copies = (data.jobs && data.jobs > 1) ? (' (' + data.jobs + ' kopie)') : '';
                        a.textContent = '✅ Wysłano' + copies;
                        setTimeout(function () { a.textContent = label; }, 2500);
                    } else {
                        a.textContent = label;
                        alert('Nie udało się wysłać: ' + (data.error || 'nieznany błąd'));
                    }
                })
                .catch(function () {
                    a.textContent = label;
                    alert('Błąd połączenia z serwerem.');
                });
        });
    });

    // Menu "Drukuj / Eksportuj": wysyłka szablonu ZPL prosto na Zebrę.
    // Klik w nazwę szablonu otwiera podgląd (zwykły link), ten przycisk pomija
    // podgląd i wrzuca gotowy wydruk do kolejki lokalnego agenta druku.
    document.querySelectorAll('.doc-agent').forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            var label = a.textContent;
            a.textContent = '⏳ Wysyłam…';
            a.classList.add('busy');

            fetch('print_document.php?agent=1&tpl=' + a.dataset.tpl
                  + '&target=' + (a.dataset.target || 'zebra')
                  + '&order=<?= (int) $wooOrderId ?>', {
                method: 'POST', body: new URLSearchParams({csrf: <?= json_encode(csrfToken()) ?>})
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.ok) {
                        a.textContent = '✅ Wysłano';
                        // Menu zostaje otwarte - widać potwierdzenie, a etykietę
                        // często drukuje się kilka razy pod rząd.
                        setTimeout(function () { a.textContent = label; a.classList.remove('busy'); }, 2500);
                    } else {
                        a.textContent = label;
                        a.classList.remove('busy');
                        alert('Nie udało się wysłać: ' + (data.error || 'nieznany błąd'));
                    }
                })
                .catch(function () {
                    a.textContent = label;
                    a.classList.remove('busy');
                    alert('Błąd połączenia z serwerem.');
                });
        });
    });
})();
</script>

<!-- Anulowanie przesyłki (każda wtyczka kurierska) -->
<script>
(function () {
    var CSRF = <?= json_encode(csrfToken()) ?>;
    // Szczegóły śledzenia: najechanie pokazuje, kliknięcie przypina (na dotyku - jedyny sposób).
    document.querySelectorAll('.trk-pill').forEach(function (pill) {
        pill.addEventListener('click', function (e) {
            e.stopPropagation();
            var box = pill.closest('.trk');
            var open = !box.classList.contains('open');
            document.querySelectorAll('.trk.open').forEach(function (o) { o.classList.remove('open'); });
            box.classList.toggle('open', open);
            pill.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    });
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.trk')) document.querySelectorAll('.trk.open').forEach(function (o) { o.classList.remove('open'); });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') document.querySelectorAll('.trk.open').forEach(function (o) { o.classList.remove('open'); });
    });

    document.querySelectorAll('.track-ship').forEach(function (btn) {
        btn.addEventListener('click', function () {
            btn.disabled = true; btn.classList.add('spin');
            var p = new URLSearchParams({ csrf: CSRF, shipment: btn.dataset.ship });
            fetch('shipment_track.php', { method: 'POST', body: p })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.ok) { location.reload(); return; }
                    alert(d.message || 'Nie udało się sprawdzić przesyłki.');
                    btn.disabled = false; btn.classList.remove('spin');
                })
                .catch(function () { alert('Błąd połączenia.'); btn.disabled = false; btn.classList.remove('spin'); });
        });
    });

    document.querySelectorAll('.ship-status-set').forEach(function (sel) {
        sel.addEventListener('change', function () {
            if (!sel.value) return;
            var label = sel.selectedOptions[0].textContent;
            if (!confirm('Ustawić status przesyłki: „' + label + '”?')) { sel.value = ''; return; }
            sel.disabled = true;
            var p = new URLSearchParams({ csrf: CSRF, action: 'set_status', shipment: sel.dataset.ship, status: sel.value });
            fetch('shipment_track.php', { method: 'POST', body: p })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.ok) { location.reload(); return; }
                    alert(d.message || 'Nie udało się zapisać statusu.');
                    sel.disabled = false; sel.value = '';
                })
                .catch(function () { alert('Błąd połączenia.'); sel.disabled = false; sel.value = ''; });
        });
    });

    document.querySelectorAll('.cancel-ship').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var q = btn.dataset.failed === '1'
                ? 'Usunąć tę pozycję z listy przesyłek?'
                : 'Anulować tę przesyłkę u przewoźnika? Tej operacji nie cofniesz.';
            if (!confirm(q)) return;
            btn.disabled = true; btn.textContent = '...';
            var p = new URLSearchParams({ csrf: CSRF, shipment: btn.dataset.ship });
            fetch('shipment_cancel.php', { method: 'POST', body: p })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    alert(d.message || (d.ok ? 'Anulowano.' : 'Błąd.'));
                    if (d.ok) location.reload();
                    else { btn.disabled = false; btn.textContent = 'Anuluj'; }
                })
                .catch(function () { alert('Błąd połączenia.'); btn.disabled = false; btn.textContent = 'Anuluj'; });
        });
    });
})();
</script>

<!-- Wybór szablonu odpowiedzi (Wiadomości z klientem) -->
<style>
.tpl-pick { display:flex; align-items:center; gap:10px; margin-bottom:8px; }
.tpl-pick-box { position:relative; flex:1; min-width:0; }
.tpl-pick-btn { width:100%; display:flex; justify-content:space-between; align-items:center; gap:8px; background:var(--surface); color:var(--ink); border:1px solid var(--line); border-radius:var(--radius-sm); padding:7px 10px; font:inherit; font-size:14px; cursor:pointer; text-align:left; }
.tpl-pick-btn span:first-child { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.tpl-pick-panel { position:absolute; z-index:30; left:0; right:0; top:calc(100% + 4px); background:var(--surface); border:1px solid var(--line); border-radius:var(--radius-sm); box-shadow:var(--shadow); padding:6px; }
.tpl-pick-panel input[type=search] { width:100%; box-sizing:border-box; margin-bottom:4px; }
.tpl-pick-list { max-height:280px; overflow-y:auto; }
.tpl-pick-group { font-size:12px; font-weight:700; color:var(--ink-2); background:var(--surface-2); border-top:1px solid var(--line); border-bottom:1px solid var(--line); padding:6px 8px; margin:4px 0; }
.tpl-pick-opt { padding:7px 8px; border-radius:6px; cursor:pointer; font-size:14px; }
.tpl-pick-opt:hover, .tpl-pick-opt.active { background:var(--accent-soft); color:var(--accent-ink); }
.tpl-pick-opt[aria-selected=true]::after { content:' ✓'; float:right; }
.tpl-pick-empty { padding:7px 8px; font-size:13px; color:var(--ink-3); }
.tpl-pick-invoice { font-size:12px; color:var(--ink-2); margin:0 0 6px; }
.tpl-pick-manage { display:block; font-size:12px; padding:6px 8px 2px; border-top:1px solid var(--line); margin-top:4px; }
</style>
<script>
(function () {
    var btn = document.getElementById('tplPickBtn');
    if (!btn) return;
    var panel = document.getElementById('tplPickPanel');
    var search = document.getElementById('tplPickSearch');
    var label = document.getElementById('tplPickLabel');
    var none = document.getElementById('tplPickNone');
    var body = document.getElementById('msgBody');
    var tplId = document.getElementById('tplPickId');
    var invoiceNote = document.getElementById('tplPickInvoice');
    var opts = Array.prototype.slice.call(document.querySelectorAll('#tplPickList .tpl-pick-opt'));
    var data = {};
    try { JSON.parse(document.getElementById('tplPickData').textContent || '[]').forEach(function (t) { data[t.id] = t; }); } catch (e) {}
    var lastFilled = '';
    var norm = function (s) { return (s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/ł/g, 'l'); };

    function visible() { return opts.filter(function (o) { return !o.hidden; }); }
    function setActive(o) {
        opts.forEach(function (x) { x.classList.toggle('active', x === o); });
        if (o) o.scrollIntoView({ block: 'nearest' });
    }
    function open() {
        panel.hidden = false; btn.setAttribute('aria-expanded', 'true');
        search.value = ''; filter(); search.focus();
    }
    function close() { panel.hidden = true; btn.setAttribute('aria-expanded', 'false'); }
    function filter() {
        var q = norm(search.value.trim()), any = false;
        opts.forEach(function (o) {
            var show = q === '' || norm(o.textContent).indexOf(q) !== -1;
            o.hidden = !show;
            if (show && o.dataset.id !== '0') any = true;
        });
        none.hidden = any || q === '';
        setActive(visible()[0] || null);
    }
    function pick(o) {
        var id = o.dataset.id;
        opts.forEach(function (x) { x.setAttribute('aria-selected', x === o ? 'true' : 'false'); });
        label.textContent = o.textContent.trim();
        close();
        tplId.value = data[id] ? id : '0';
        invoiceNote.hidden = !(data[id] && data[id].attach_invoice);
        if (id === '0' || !data[id]) { btn.focus(); return; }
        var cur = body.value.trim();
        if (cur !== '' && cur !== lastFilled.trim() && !confirm('Zastąpić wpisaną treść szablonem „' + data[id].name + '"?')) { btn.focus(); return; }
        body.value = data[id].body;
        lastFilled = body.value;
        body.rows = Math.min(14, Math.max(3, body.value.split('\n').length + 1));
        body.focus();
    }

    btn.addEventListener('click', function () { panel.hidden ? open() : close(); });
    search.addEventListener('input', filter);
    search.addEventListener('keydown', function (e) {
        var v = visible(), i = v.indexOf(v.filter(function (o) { return o.classList.contains('active'); })[0]);
        if (e.key === 'ArrowDown') { e.preventDefault(); setActive(v[Math.min(v.length - 1, i + 1)] || null); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(v[Math.max(0, i - 1)] || null); }
        else if (e.key === 'Enter') { e.preventDefault(); if (i >= 0) pick(v[i]); }
        else if (e.key === 'Escape') { close(); btn.focus(); }
    });
    opts.forEach(function (o) { o.addEventListener('click', function () { pick(o); }); });
    document.addEventListener('click', function (e) { if (!document.getElementById('tplPick').contains(e.target)) close(); });
})();
</script>

<!-- Kopiowanie linku dla klienta -->
<script>
(function () {
    // Wątek wiadomości: zwiń długie wiadomości, przewiń do najnowszej.
    (function () {
        var t = document.getElementById('msgThread');
        if (!t) { return; }
        t.querySelectorAll('.msg-body').forEach(function (b) {
            b.classList.add('is-clamped');
            if (b.scrollHeight <= b.clientHeight + 4) { b.classList.remove('is-clamped'); return; }
            var more = document.createElement('button');
            more.type = 'button'; more.className = 'msg-more'; more.textContent = 'Pokaż całość ▾';
            more.addEventListener('click', function () {
                var open = b.classList.toggle('is-clamped') === false;
                more.textContent = open ? 'Zwiń ▴' : 'Pokaż całość ▾';
            });
            b.after(more);
        });
        t.scrollTop = t.scrollHeight;
    })();
    var btn = document.getElementById('copyClientLink');
    var input = document.getElementById('clientLinkInput');
    if (!btn || !input) return;
    btn.addEventListener('click', function () {
        var done = function () {
            var old = btn.textContent;
            btn.textContent = '✅ Skopiowano';
            setTimeout(function () { btn.textContent = old; }, 1500);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(input.value).then(done).catch(fallback);
        } else {
            fallback();
        }
        function fallback() {
            input.removeAttribute('readonly');
            input.select();
            try { document.execCommand('copy'); done(); } catch (e) { input.focus(); }
            input.setAttribute('readonly', 'readonly');
        }
    });
})();
</script>

<style>
    #documentConfirmDialog { width:min(440px, calc(100vw - 48px)); box-sizing:border-box; padding:24px; border:1px solid var(--line); border-radius:16px; background:var(--surface); color:var(--ink); font-family:var(--font-ui); box-shadow:0 20px 60px rgba(0,0,0,.2); }
    #documentConfirmDialog::backdrop { background:rgba(30,25,15,.45); }
    #documentConfirmDialog h2 { margin:0 0 12px; font-size:20px; }
    #documentConfirmDialog p { margin:0 0 24px; line-height:1.6; color:var(--ink-2); }
    #documentConfirmDialog .confirm-actions { display:flex; justify-content:flex-end; gap:10px; flex-wrap:wrap; }
    #documentConfirmProceed.danger { background:var(--danger-ink); color:white; }
</style>
<dialog id="documentConfirmDialog" aria-labelledby="documentConfirmTitle" aria-describedby="documentConfirmText">
    <h2 id="documentConfirmTitle">Potwierdź operację</h2>
    <p id="documentConfirmText"></p>
    <div class="confirm-actions">
        <button type="button" class="btn secondary" id="documentConfirmCancel" autofocus>Anuluj</button>
        <button type="button" class="btn" id="documentConfirmProceed">Potwierdź</button>
    </div>
</dialog>
<script>
(function () {
    const dialog = document.getElementById('documentConfirmDialog');
    const proceed = document.getElementById('documentConfirmProceed');
    let pendingForm = null;
    let approvedForm = null;
    let submitting = false;
    document.querySelectorAll('form[data-document-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (form === approvedForm && !submitting) {
                approvedForm = null;
                submitting = true;
                form.querySelectorAll('button[type="submit"]').forEach(function (button) { button.disabled = true; });
                return;
            }
            event.preventDefault();
            if (submitting || dialog.open) return;
            pendingForm = form;
            document.getElementById('documentConfirmText').textContent = form.dataset.documentConfirm;
            document.getElementById('documentConfirmTitle').textContent = form.dataset.confirmLabel;
            proceed.textContent = form.dataset.confirmLabel;
            proceed.classList.toggle('danger', form.dataset.confirmDanger === 'true');
            dialog.showModal();
        });
    });
    document.getElementById('documentConfirmCancel').addEventListener('click', function () { dialog.close(); });
    dialog.addEventListener('close', function () { pendingForm = null; });
    proceed.addEventListener('click', function () {
        const form = pendingForm;
        if (!form || submitting) return;
        dialog.close();
        approvedForm = form;
        form.requestSubmit();
        approvedForm = null;
    });
})();
</script>
<!-- Lightbox miniatur produktów: klik = podgląd, Esc / klik w tło = zamknij, strzałki = poprzednie/następne. -->
<div id="thumb-lightbox" hidden>
    <button type="button" class="tlb-close" aria-label="Zamknij">✕</button>
    <button type="button" class="tlb-nav tlb-prev" aria-label="Poprzednie">‹</button>
    <figure>
        <img alt="">
        <figcaption></figcaption>
    </figure>
    <button type="button" class="tlb-nav tlb-next" aria-label="Następne">›</button>
</div>
<style>
#thumb-lightbox{position:fixed;inset:0;z-index:9999;background:rgba(20,18,15,.86);display:flex;align-items:center;justify-content:center;padding:24px}
#thumb-lightbox[hidden]{display:none}
#thumb-lightbox figure{margin:0;max-width:min(90vw,1000px);text-align:center}
#thumb-lightbox img{max-width:100%;max-height:calc(80vh / var(--ui-zoom, 1));border-radius:10px;background:#fff;box-shadow:0 10px 40px rgba(0,0,0,.4);display:block;margin:0 auto}
#thumb-lightbox figcaption{color:#f5f3ee;font-size:14px;margin-top:12px;line-height:1.4}
#thumb-lightbox button{position:absolute;border:0;background:rgba(255,255,255,.12);color:#fff;cursor:pointer;border-radius:50%;width:44px;height:44px;font-size:22px;line-height:44px;padding:0}
#thumb-lightbox button:hover{background:rgba(255,255,255,.25)}
#thumb-lightbox .tlb-close{top:16px;right:16px;font-size:18px}
#thumb-lightbox .tlb-prev{left:16px;top:50%;transform:translateY(-50%);font-size:30px}
#thumb-lightbox .tlb-next{right:16px;top:50%;transform:translateY(-50%);font-size:30px}
a.item-thumb{cursor:zoom-in}
</style>
<script>
(function () {
    const box = document.getElementById('thumb-lightbox');
    if (!box) return;
    const img = box.querySelector('img');
    const cap = box.querySelector('figcaption');
    const prev = box.querySelector('.tlb-prev');
    const next = box.querySelector('.tlb-next');
    let links = [];
    let cur = 0;

    function show(i) {
        cur = (i + links.length) % links.length;
        img.src = links[cur].href;
        cap.textContent = links[cur].dataset.caption || '';
        const many = links.length > 1;
        prev.hidden = !many; next.hidden = !many;
    }
    function open(i) { links = Array.from(document.querySelectorAll('a.item-thumb')); show(i); box.hidden = false; document.body.style.overflow = 'hidden'; }
    function close() { box.hidden = true; img.removeAttribute('src'); document.body.style.overflow = ''; }

    document.addEventListener('click', function (e) {
        const a = e.target.closest('a.item-thumb');
        if (!a || e.metaKey || e.ctrlKey || e.shiftKey) return; // Cmd/Ctrl+klik = nowa karta jak dawniej
        e.preventDefault();
        open(Array.from(document.querySelectorAll('a.item-thumb')).indexOf(a));
    });
    box.addEventListener('click', function (e) { if (e.target === box || e.target.tagName === 'FIGURE') close(); });
    box.querySelector('.tlb-close').addEventListener('click', close);
    prev.addEventListener('click', function (e) { e.stopPropagation(); show(cur - 1); });
    next.addEventListener('click', function (e) { e.stopPropagation(); show(cur + 1); });
    document.addEventListener('keydown', function (e) {
        if (box.hidden) return;
        if (e.key === 'Escape') close();
        else if (e.key === 'ArrowLeft' && links.length > 1) show(cur - 1);
        else if (e.key === 'ArrowRight' && links.length > 1) show(cur + 1);
    });
})();
</script>

<?php if (canEdit()): ?>
<script>
// Dane do faktury: edycja w miejscu + pobranie danych firmy po NIP (GUS / Biała lista VAT).
function invToggle(open) {
    const f = document.getElementById('invEdit'), show = document.getElementById('invShow');
    if (!f) return;
    f.hidden = !open; show.hidden = open;
    document.getElementById('invMenu').hidden = true;
    if (open) (document.getElementById('invNip').value ? document.getElementById('invName') : document.getElementById('invNip')).focus();
}
function invMenu() { const m = document.getElementById('invMenu'); m.hidden = !m.hidden; }
document.addEventListener('click', (e) => {
    const m = document.getElementById('invMenu');
    if (m && !m.hidden && !e.target.closest('.inv-gus')) m.hidden = true;
});
function invMsg(text, cls) { const el = document.getElementById('invMsg'); el.textContent = text; el.className = 'inv-msg ' + (cls || ''); }
function invPost(data) {
    const fd = new FormData();
    fd.append('csrf', <?= json_encode(csrfToken()) ?>);
    for (const k in data) fd.append(k, data[k]);
    return fetch(location.pathname + location.search, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then((r) => r.json().catch(() => ({ ok: false, error: 'Serwer zwrócił błąd (' + r.status + ').' })));
}
async function invLookup(source) {
    document.getElementById('invMenu').hidden = true;
    const nip = document.getElementById('invNip').value.trim();
    if (!nip) { invMsg('Wpisz NIP.', 'bad'); document.getElementById('invNip').focus(); return; }
    const btn = document.getElementById('invGusBtn');
    btn.disabled = true; invMsg('Szukam firmy…');
    try {
        const r = await invPost({ action: 'company_lookup', nip, source });
        if (!r.ok) { invMsg(r.error || 'Nie udało się pobrać danych.', 'bad'); return; }
        const c = r.company;
        const set = (id, v) => { const el = document.getElementById(id); if (v !== undefined && v !== null && v !== '') { el.value = v; el.classList.add('inv-filled'); setTimeout(() => el.classList.remove('inv-filled'), 1600); } };
        set('invCompany', c.company); set('invAddr', c.address_1); set('invPost', c.postcode); set('invCity', c.city);
        set('invState', c.state); set('invNip', c.nip);
        const cc = document.getElementById('invCountry'); if (c.country) cc.value = c.country;
        const wants = document.querySelector('#invEdit input[name=wants]'); if (wants) wants.checked = true;
        invMsg('✓ Pobrano: ' + c.source + (c.regon ? ' · REGON ' + c.regon : '') + (c.closed ? ' · uwaga: ' + (/^\d{4}/.test(c.closed) ? 'działalność zakończona ' + c.closed : c.closed) : '') + '. Sprawdź i kliknij Zapisz.', c.closed ? 'bad' : 'ok');
    } catch (e) {
        invMsg('Brak połączenia z serwerem.', 'bad');
    } finally { btn.disabled = false; }
}
async function invKey() {
    document.getElementById('invMenu').hidden = true;
    const key = prompt('Klucz API GUS (BIR 1.1). Bezpłatny — zamówisz go na stronie api.stat.gov.pl. Puste pole usuwa klucz; wtedy dane pobieramy z Białej listy VAT.');
    if (key === null) return;
    const r = await invPost({ action: 'save_gus_key', gus_key: key.trim() });
    invMsg(r.ok ? (r.hasKey ? '✓ Zapisano klucz GUS.' : 'Usunięto klucz GUS — wyszukiwanie przez Białą listę VAT.') : (r.error || 'Nie zapisano.'), r.ok ? 'ok' : 'bad');
}
document.getElementById('invNip')?.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); invLookup('auto'); } });
</script>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
