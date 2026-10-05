<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Okienko „Opis ikon zamówienia” na liście zamówień (Services\OrderIconDetails): dokumenty wFirma i przesyłki.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Services\OrderIconDetails as D;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

check(D::documentNumber('Paragon PAR 168/2026 wystawiony poza CRM, powiązany z wFirma.') === 'PAR 168/2026', 'number from linked receipt');
check(D::documentNumber('Faktura FV 12/09/2026 wystawiony poza CRM, powiązany z wFirma.') === 'FV 12/09/2026', 'number from linked invoice');
check(D::documentNumber('Dokument FV 3/2026 wystawiony.') === 'FV 3/2026', 'number from document issued in CRM');
check(D::documentNumber('Dokument wystawiony.') === null, 'old message without number');
check(D::documentNumber('') === null, 'empty message');

$docs = D::documents([
    ['status' => 'issued', 'provider' => 'wfirma', 'document_type' => 'receipt', 'remote_id' => '555',
     'message' => 'Paragon PAR 168/2026 wystawiony poza CRM, powiązany z wFirma.', 'created_at' => '2026-09-28 10:00:00'],
    ['status' => 'issued', 'provider' => 'wfirma', 'document_type' => 'normal', 'remote_id' => '777', 'message' => 'Dokument wystawiony.'],
    ['status' => 'pending', 'provider' => 'wfirma', 'document_type' => 'proforma', 'remote_id' => '9', 'message' => ''],
]);
check(count($docs) === 2, 'only issued documents');
check($docs[0]['kind'] === 'receipt' && $docs[0]['number'] === 'PAR 168/2026' && $docs[0]['url'] === 'https://wfirma.pl/invoices/view/555'
    && $docs[0]['missing'] === [], 'receipt: number + wFirma link');
check($docs[1]['kind'] === 'invoice' && $docs[1]['number'] === null && $docs[1]['display'] === 'ID 777'
    && $docs[1]['url'] !== null && count($docs[1]['missing']) === 1, 'invoice without saved number: ID shown, missing number explained');

$ships = D::shipments([
    ['status' => 'created', 'waybill_no' => '620999673376004676945119', 'courier_code' => 'InPost Paczkomaty', 'tracking_status' => 'ready_for_pickup',
     'tracking_events' => json_encode([['at' => '2026-09-27 12:00:00', 'desc' => 'Nadana', 'code' => 'x'], ['at' => '2026-09-28 08:57:00', 'desc' => 'Oczekuje w punkcie', 'code' => 'y']])],
    ['status' => 'created', 'waybill_no' => 'ABC1', 'courier_code' => 'Kurier Zenek (ręcznie)'],
    ['status' => 'pending', 'waybill_no' => null, 'courier_code' => 'dpd'],
    ['status' => 'cancelled', 'waybill_no' => 'X9', 'courier_code' => 'dpd'],
]);
check($ships[0]['url'] === 'https://inpost.pl/sledzenie-przesylek?number=620999673376004676945119' && $ships[0]['status'] === 'Czeka w punkcie odbioru'
    && $ships[0]['last_event'] === 'Oczekuje w punkcie' && $ships[0]['last_at'] === '28.09.2026 10:57' && $ships[0]['progress'] === 85, 'InPost: link, stage, last event in Polish time');
check($ships[1]['url'] === null && $ships[1]['courier'] === 'Kurier Zenek' && str_contains($ships[1]['missing'][0], 'przewoźnika'), 'unknown carrier: no link, reason given');
check($ships[2]['waybill'] === null && str_contains($ships[2]['missing'][0], 'numeru listu'), 'no waybill yet explained');
check($ships[3]['cancelled'] && $ships[3]['url'] === null && $ships[3]['progress'] === 0 && $ships[3]['missing'] === [], 'cancelled shipment: no tracking link');

foreach (D::LEGEND as $entry) {
    check(isset(D::ICON_PATHS[$entry['kind']]), 'legend icon exists: ' . $entry['label']);
}

echo "\n{$checks} checks passed\n";
