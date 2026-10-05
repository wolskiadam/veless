<?php
declare(strict_types=1);

namespace Pase\Services;

use Pase\Plugin\Contract\Invoicing;
use Pase\Plugin\PluginRegistry;
use Pase\Repository\IntegrationAccountRepository;
use Pase\Repository\OrderDocumentRepository;
use Pase\Repository\WooOrderRepository;
use PDO;

/** Ręczne wystawianie dokumentów wFirma z zamówienia WooCommerce. */
final class WfirmaDocumentService
{
    private const TYPES = ['normal', 'receipt', 'receipt_fiscal', 'proforma'];

    public function __construct(private readonly PDO $pdo, private readonly ?\Closure $invoiceFactory = null) {}

    /** Usuwa wskazany paragon z konta, na którym go wystawiono, a potem lokalnie. */
    public function deleteReceipt(int $wooOrderId, int $documentId): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM order_documents WHERE id = ? AND woo_order_id = ?
             AND provider = 'wfirma' AND document_type IN ('receipt', 'receipt_fiscal')"
        );
        $stmt->execute([$documentId, $wooOrderId]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$document) {
            throw new \RuntimeException('Nie znaleziono paragonu dla tego zamówienia.');
        }
        $integration = (new IntegrationAccountRepository($this->pdo))->find((int) $document['integration_id']);
        if (!$integration || $integration['type'] !== 'wfirma' || empty($integration['is_active'])) {
            throw new \RuntimeException('Integracja wFirma użyta do wystawienia paragonu jest niedostępna lub wyłączona.');
        }
        (new \PasePlugin\Wfirma\WfirmaClient($integration['config']))->deleteDocument((string) $document['remote_id']);
        try {
            (new OrderDocumentRepository($this->pdo))->unlinkReceipt($documentId, $wooOrderId, true);
        } catch (\PDOException $e) {
            throw new \RuntimeException('Paragon usunięto z wFirma, ale lokalny zapis się nie powiódł. Użyj opcji Usuń powiązanie.', 0, $e);
        }
    }

    /**
     * Wystawia dokument, a potem uruchamia reguły „Wystawiono fakturę” / „Wystawiono paragon”.
     * @return array{document_id:string,message:string}
     */
    public function issue(int $wooOrderId, string $type): array
    {
        $result = $this->issueDocument($wooOrderId, $type);
        \Pase\Automation\OrderEvents::documentIssued($this->pdo, $wooOrderId, $type);
        return $result;
    }

    /** @return array{document_id:string,message:string} */
    private function issueDocument(int $wooOrderId, string $type): array
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \RuntimeException('Nieobsługiwany typ dokumentu.');
        }

        $order = (new WooOrderRepository($this->pdo))->find($wooOrderId);
        if ($order === null) {
            throw new \RuntimeException('Nie znaleziono zamówienia.');
        }

        if (!empty($order['merged_into'])) {
            // Scalone zamówienia mają jeden dokument na całą paczkę - wystawia go zamówienie główne.
            $main = (new OrderMerge($this->pdo))->targetOf($wooOrderId);
            throw new \RuntimeException('To zamówienie jest scalone z ' . ($main !== null ? OrderMerge::label($main) : 'innym')
                . ' - wystaw dokument na zamówieniu głównym, obejmie całą paczkę.');
        }
        try {
            $merged = (new OrderMerge($this->pdo))->absorbedForDocument($wooOrderId);
        } catch (\PDOException) {
            $merged = [];   // baza bez scalania (przed migracją)
        }

        $integration = (new IntegrationAccountRepository($this->pdo))->firstActive('wfirma');
        if ($integration === null) {
            throw new \RuntimeException('Brak aktywnej integracji wFirma.');
        }

        $documents = new OrderDocumentRepository($this->pdo);
        if ($documents->findForOrderAndType($wooOrderId, 'wfirma', $type) !== null) {
            throw new \RuntimeException('Ten typ dokumentu został już wystawiony dla zamówienia.');
        }

        $plugin = $this->invoiceFactory !== null
            ? ($this->invoiceFactory)($integration['config'])
            : PluginRegistry::forAccount('wfirma', $integration['config']);
        if (!$plugin instanceof Invoicing) {
            throw new \RuntimeException('Wtyczka wFirma nie jest dostępna lub jest wyłączona.');
        }

        $payload = json_decode((string) ($order['payload'] ?? ''), true) ?: [];
        $items = $order['local_items'] !== null
            ? (json_decode((string) $order['local_items'], true) ?: [])
            : ($payload['line_items'] ?? []);
        $items = $order['local_items'] !== null ? $this->withShopTax($items, $payload) : $items;
        $positions = $this->positions($items, $payload, (int) ($integration['config']['default_vat'] ?? 23));
        // Scalone: pozycje i dostawa dołączonych zamówień na tym samym dokumencie (jedna paczka, jeden dokument).
        // Opłacone w sklepie: przy pozycjach poprawionych w CRM (nazwy, ilości) klient zapłacił kwotę ze sklepu,
        // więc na dokumencie zapłacono tyle, ale nie więcej niż wynosi dokument.
        $alreadyPaid = !empty($payload['date_paid']) ? (float) ($payload['total'] ?? 0) : null;
        $anyEdited = $order['local_items'] !== null;
        foreach ($merged as $m) {
            $mPayload = json_decode((string) ($m['payload'] ?? ''), true) ?: [];
            $mItems = ($m['local_items'] ?? null) !== null ? $this->withShopTax(OrderMerge::items($m), $mPayload) : OrderMerge::items($m);
            $positions = array_merge($positions, $this->positions($mItems, $mPayload, (int) ($integration['config']['default_vat'] ?? 23)));
            $alreadyPaid = $alreadyPaid !== null && !empty($mPayload['date_paid'])
                ? $alreadyPaid + (float) ($mPayload['total'] ?? 0) : null;
            $anyEdited = $anyEdited || ($m['local_items'] ?? null) !== null;
        }
        $docGross = round(array_sum(array_map(static fn(array $p): float => (float) $p['price'] * (int) $p['count'], $positions)), 2);
        if ($alreadyPaid !== null && $anyEdited) {
            $alreadyPaid = min($alreadyPaid, $docGross);
        }
        // Wpłata potwierdzona ręcznie w CRM (OrderPayment) zastępuje dane ze sklepu.
        $manualPaid = OrderPayment::state($order);
        if ($manualPaid !== null) {
            $docTotal = OrderMerge::orderTotal($order) + array_sum(array_map(static fn(array $m): float => OrderMerge::orderTotal($m), $merged));
            // Suma pozycji po edycji w CRM nie obejmuje dostawy - limitem jest wtedy kwota samego dokumentu.
            $docTotal = $anyEdited ? max($docTotal, $docGross) : $docTotal;
            $alreadyPaid = $manualPaid['amount'] > 0 ? min($manualPaid['amount'], round($docTotal, 2)) : null;
        }
        if ($positions === []) {
            throw new \RuntimeException('Zamówienie nie ma pozycji, z których można wystawić dokument.');
        }

        $guard = new DocumentIssueGuard($this->pdo);
        $token = $guard->reserve($wooOrderId, $type, (int) $integration['id']);
        $remoteId = null;
        try {
        $result = $plugin->issueDocument(
            $this->contractor($payload, $order),
            $positions,
            $type,
            [
                'currency'    => (string) ($payload['currency'] ?? $order['currency'] ?? 'PLN'),
                'id_external' => 'PASE-WOO-' . $wooOrderId . '-' . $type,
                'description' => (isset($payload['buyer']) ? 'Zamówienie Allegro #' : 'Zamówienie WooCommerce #') . (string) ($order['order_number'] ?? $wooOrderId)
                    . implode('', array_map(static fn(array $m): string => ' + #' . (string) ($m['order_number'] ?? $m['woo_order_id']), $merged)),
                'paymentmethod' => match ((string) ($payload['payment_method'] ?? '')) {
                    'cod' => 'cod',
                    'bacs' => 'transfer',
                    default => null,
                },
                'alreadypaid_initial' => $alreadyPaid !== null ? ($merged === [] && $manualPaid === null && !$anyEdited ? (string) ($payload['total'] ?? '0') : number_format($alreadyPaid, 2, '.', '')) : null,
            ]
        );
        if (empty($result['ok'])) {
            throw new \RuntimeException((string) ($result['message'] ?? 'wFirma odrzuciła dokument.'));
        }

        $remoteId = trim((string) ($result['document_id'] ?? ''));
        if ($remoteId === '') {
            throw new \RuntimeException('wFirma utworzyła dokument, ale nie zwróciła jego ID. Sprawdź dokumenty bezpośrednio w wFirma.');
        }

        try {
            (new AuditTrail($this->pdo))->atomic(function () use ($documents, $wooOrderId, $integration, $type, $remoteId, $result, $guard, $token): void {
            $documents->create(
                $wooOrderId,
                (int) $integration['id'],
                'wfirma',
                $type,
                $remoteId,
                (string) ($result['message'] ?? '')
            );
            $guard->finish($wooOrderId, $type, $token, $remoteId);
            });
        } catch (\Throwable $e) {
            throw new \RuntimeException('wFirma zwróciła dokument ID ' . $remoteId
                . ', ale lokalny zapis dokumentu lub historii nie powiódł się. Nie ponawiaj wystawiania; sprawdź dokument w wFirma.', 0, $e);
        }

        return ['document_id' => $remoteId, 'message' => (string) ($result['message'] ?? '')];
        } catch (\Throwable $e) {
            // If the DB is down, the already committed pending row still blocks retries.
            try { $guard->uncertain($wooOrderId, $type, $token, $remoteId); } catch (\Throwable) {}
            throw new \RuntimeException($e->getMessage()
                . ' Ponowne wystawienie zablokowane. Administrator musi sprawdzić wynik w wFirma.', 0, $e);
        }
    }

    /** @return array<string,string> */
    private function contractor(array $payload, array $order): array
    {
        // Dane do faktury: lokalna poprawka z CRM (woo_orders.local_invoice) albo oryginał ze sklepu / Allegro.
        $billing = InvoiceData::billing($payload, isset($order['local_invoice']) ? (string) $order['local_invoice'] : null);
        $nip = (string) ($billing['nip'] ?? '');

        $person = trim((string) ($billing['first_name'] ?? '') . ' ' . (string) ($billing['last_name'] ?? ''));
        $data = [
            'name'       => trim((string) ($billing['company'] ?? '')) ?: ($person ?: (string) ($order['customer_name'] ?? 'Klient detaliczny')),
            'nip'        => preg_replace('/[\s-]+/', '', $nip) ?? '',
            'tax_id_type' => (($billing['country'] ?? 'PL') === 'PL') ? 'nip' : 'custom',
            'contact_person' => $person,
            'email'      => (string) ($order['local_billing_email'] ?? $billing['email'] ?? $order['customer_email'] ?? ''),
            'phone'      => (string) ($order['local_billing_phone'] ?? $billing['phone'] ?? ''),
            'address_1'  => trim((string) ($billing['address_1'] ?? '') . ' ' . (string) ($billing['address_2'] ?? '')),
            'postcode'   => (string) ($billing['postcode'] ?? ''),
            'city'       => (string) ($billing['city'] ?? ''),
            'country'    => (string) ($billing['country'] ?? 'PL'),
        ];
        $shipping = $payload['shipping'] ?? [];
        if ((!is_array($shipping) || empty($shipping['address_1'])) && !empty($order['local_invoice'])) {
            // Sklep zostawia adres dostawy pusty, gdy = dane do faktury. Po poprawce danych do faktury
            // w CRM adresem kontaktowym zostaje oryginalny adres klienta (tam idzie paczka).
            $orig = InvoiceData::originalBilling($payload);
            if (trim((string) ($orig['address_1'] ?? '')) !== ''
                && trim((string) ($orig['address_1'] ?? '')) . ($orig['city'] ?? '') !== $data['address_1'] . $data['city']) {
                $shipping = $orig;
            }
        }
        if (is_array($shipping) && !empty($shipping['address_1'])) {
            $data += [
                'different_contact_address' => '1',
                'contact_name' => trim((string) ($shipping['company'] ?? ''))
                    ?: trim(($shipping['first_name'] ?? '') . ' ' . ($shipping['last_name'] ?? '')),
                'contact_street' => trim(($shipping['address_1'] ?? '') . ' ' . ($shipping['address_2'] ?? '')),
                'contact_zip' => (string) ($shipping['postcode'] ?? ''),
                'contact_city' => (string) ($shipping['city'] ?? ''),
                'contact_country' => (string) ($shipping['country'] ?? ''),
            ];
        }
        return $data;
    }

    /** @return array<int,array{name:string,count:int,price:string,vat:int,unit:string}> */
    private function positions(array $items, array $payload, int $defaultVat): array
    {
        $positions = [];
        foreach ($items as $item) {
            $qty = max(1, (int) ($item['quantity'] ?? 1));
            $lineNet = $this->amount($item['total'] ?? null);
            $hasTax = array_key_exists('total_tax', $item);
            $lineTax = $hasTax ? $this->amount($item['total_tax']) : null;
            $gross = $lineNet !== null
                ? $lineNet + ($lineTax ?? 0.0)
                : ((float) ($item['price'] ?? 0) * $qty);
            $positions[] = [
                'name'  => trim((string) ($item['name'] ?? '')) ?: 'Pozycja zamówienia',
                'count' => $qty,
                'price' => number_format($gross / $qty, 2, '.', ''),
                'vat'   => $this->vatRate($lineNet, $lineTax, $defaultVat),
                'unit'  => 'szt.',
            ];
        }

        foreach (['shipping_lines' => 'Dostawa', 'fee_lines' => 'Opłata'] as $key => $fallbackName) {
            foreach ($payload[$key] ?? [] as $line) {
                $net = $this->amount($line['total'] ?? null);
                $hasTax = array_key_exists('total_tax', $line);
                $tax = $hasTax ? $this->amount($line['total_tax']) : null;
                $gross = ($net ?? 0.0) + ($tax ?? 0.0);
                if (abs($gross) < 0.005) {
                    continue;
                }
                $positions[] = [
                    'name'  => trim((string) ($line['method_title'] ?? $line['name'] ?? '')) ?: $fallbackName,
                    'count' => 1,
                    'price' => number_format($gross, 2, '.', ''),
                    'vat'   => $this->vatRate($net, $tax, $defaultVat),
                    'unit'  => 'szt.',
                ];
            }
        }

        return $positions;
    }

    /**
     * Pozycje poprawione w CRM przed zachowaniem VAT-u (starsze edycje) nie mają total_tax, choć sklep podał ceny
     * netto - dokładamy VAT za sztukę z oryginalnej pozycji tego samego produktu, żeby netto nie poszło jako brutto.
     */
    private function withShopTax(array $items, array $payload): array
    {
        $taxPerUnit = [];
        foreach ($payload['line_items'] ?? [] as $line) {
            if (is_array($line) && is_numeric($line['total_tax'] ?? null)) {
                $key = ($line['product_id'] ?? '') . '|' . ($line['sku'] ?? '');
                $taxPerUnit[$key] ??= (float) $line['total_tax'] / max(1, (int) ($line['quantity'] ?? 1));
            }
        }
        foreach ($items as $i => $item) {
            $key = ($item['product_id'] ?? '') . '|' . ($item['sku'] ?? '');
            if (is_array($item) && !array_key_exists('total_tax', $item) && isset($taxPerUnit[$key])) {
                $items[$i]['total_tax'] = round($taxPerUnit[$key] * max(1, (int) ($item['quantity'] ?? 1)), 2);
            }
        }
        return $items;
    }

    private function amount(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }

    private function vatRate(?float $net, ?float $tax, int $fallback): int
    {
        if ($net === null || abs($net) < 0.00001 || $tax === null) {
            return $fallback;
        }
        $calculated = ($tax / $net) * 100;
        foreach ([23, 8, 5, 0] as $rate) {
            if (abs($calculated - $rate) < 0.75) {
                return $rate;
            }
        }
        return $fallback;
    }
}
