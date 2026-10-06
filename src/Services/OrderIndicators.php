<?php
declare(strict_types=1);

namespace Pase\Services;

/** Read-only indicators based on the imported order and locally recorded shipments. */
final class OrderIndicators
{
    /** @return array<string,array{state:string,label:string}> */
    public static function forOrder(array $order, array $payload, ?array $shipments): array
    {
        $status = $payload['status'] ?? $order['status'] ?? '';
        $payment = self::indicator('muted', 'payment_unknown');
        $manual = OrderPayment::state($order);
        if ($status === 'refunded') {
            $payment = self::indicator('muted', 'payment_refunded');
        } elseif (PaymentFailure::failed($order, $payload)) {
            // Sklep zgłosił nieudaną płatność i nic jej nie zastąpiło (ręcznie potwierdzona pełna wpłata wygrywa).
            $payment = self::indicator('bad', 'payment_failed');
        } elseif ($manual !== null) {
            // Wpłata potwierdzona ręcznie w CRM ma pierwszeństwo przed danymi ze sklepu.
            $payment = self::indicator($manual['state'], $manual['label'] === 'payment_unpaid' && ($payload['payment_method'] ?? '') === 'cod'
                ? 'payment_cod' : $manual['label']);
        } elseif (!empty($payload['date_paid']) || !empty($payload['date_paid_gmt'])) {
            $payment = self::indicator('ok', 'payment_paid');
        } elseif (array_key_exists('date_paid', $payload) || array_key_exists('date_paid_gmt', $payload)) {
            $payment = ($payload['payment_method'] ?? '') === 'cod'
                ? self::indicator('warn', 'payment_cod')
                : self::indicator('warn', 'payment_unpaid');
        }

        // Only explicit invoice fields are interpreted as a request. Company name alone is insufficient.
        // A correction saved in the CRM (local_invoice) decides.
        $local = isset($order['local_invoice']) && $order['local_invoice'] !== '' ? (string) $order['local_invoice'] : null;
        $requested = InvoiceData::requested($payload, $local);
        $nip = trim(InvoiceData::nip($payload, $local));
        $invoice = $requested === true ? self::indicator('warn', 'invoice_required')
            : ($requested === false ? self::indicator('muted', 'invoice_not_requested')
            : ($nip !== '' ? self::indicator('warn', 'invoice_nip') : self::indicator('muted', 'invoice_unknown')));

        $shipping = self::indicator('muted', 'shipping_unknown');
        $internalStatus = $order['pase_status'] ?? '';
        $lines = $payload['shipping_lines'] ?? [];
        $pickup = count($lines) > 0;
        foreach ($lines as $line) {
            if (explode(':', (string)($line['method_id'] ?? ''))[0] !== 'local_pickup') { $pickup = false; }
        }
        $virtual = !empty($payload['line_items']);
        foreach ($payload['line_items'] ?? [] as $item) {
            if (($item['virtual'] ?? null) !== true) { $virtual = false; }
        }
        $activeShipments = array_filter($shipments ?? [], static fn(array $s): bool =>
            !in_array($s['status'] ?? '', ['cancelled', 'canceled', 'failed'], true));
        if (in_array($internalStatus, ['cancelled', 'refunded'], true) || in_array($status, ['cancelled', 'refunded'], true)) {
            $shipping = self::indicator('muted', 'shipping_inactive');
        } elseif ($activeShipments !== [] && array_filter($activeShipments, static fn(array $s): bool =>
                ($s['tracking_status'] ?? '') !== 'delivered') === []) {
            // Etap od przewoźnika albo ustawiony ręcznie (Services\ShipmentTracking).
            $shipping = self::indicator('ok', 'shipping_delivered');
        } elseif (array_filter($activeShipments, static fn(array $s): bool =>
                in_array($s['tracking_status'] ?? '', ['returned', 'issue'], true)) !== []) {
            $shipping = self::indicator('warn', 'shipping_problem');
        } elseif ($internalStatus === 'shipped') {
            $shipping = self::indicator('ok', 'shipping_sent');
        } elseif (array_filter($activeShipments, static fn(array $s): bool => in_array($s['tracking_status'] ?? '',
                ['in_transit', 'out_for_delivery', 'ready_for_pickup', 'notice_left', 'delivered'], true)) !== []) {
            $shipping = self::indicator('ok', 'shipping_in_transit');
        } elseif ($activeShipments !== []) {
            $shipping = self::indicator('info', 'shipping_created');
        } elseif (!empty($order['no_shipping'])) {
            // Oznaczone w CRM jako zamówienie wirtualne (bez wysyłki).
            $shipping = self::indicator('muted', 'shipping_not_needed');
        } elseif ($pickup) {
            $shipping = self::indicator('info', 'shipping_pickup');
        } elseif ($virtual || ($payload['needs_shipping'] ?? null) === false) {
            $shipping = self::indicator('muted', 'shipping_not_needed');
        } elseif ($shipments !== null && ($lines !== [] || ($payload['needs_shipping'] ?? null) === true)) {
            $shipping = self::indicator('warn', 'shipping_needed');
        }
        return ['payment' => self::recolor($payment), 'invoice' => $invoice, 'shipping' => self::recolor($shipping)];
    }

    /**
     * Kolory ikon płatności i wysyłki (stopniowanie, żeby nie wszystko było pomarańczowe):
     * płatność - opłacone zielone, częściowo zielono-pomarańczowe, za pobraniem pomarańczowe,
     * nieopłacone i nieudane czerwone; wysyłka - do wysłania pomarańczowa, utworzona neutralna,
     * wysłana / w drodze niebieska, doręczona zielona, problem czerwony.
     */
    private const COLORS = [
        'orders.indicator.payment_partial' => 'partial',
        'orders.indicator.payment_unpaid' => 'bad',
        'orders.indicator.payment_cod' => 'warn',
        'orders.indicator.shipping_sent' => 'transit',
        'orders.indicator.shipping_in_transit' => 'transit',
        'orders.indicator.shipping_problem' => 'bad',
    ];

    /** @param array{state:string,label:string} $indicator */
    private static function recolor(array $indicator): array
    {
        if ($indicator['state'] !== 'muted' && isset(self::COLORS[$indicator['label']])) {
            $indicator['state'] = self::COLORS[$indicator['label']];
        }
        return $indicator;
    }

    /** Filtr „Wysyłka" na liście zamówień: etapy śledzenia przesyłki (ShipmentTracking::STATUSES) zgrupowane dla filtra. */
    public const TRACKING_FILTERS = [
        'created'          => ['Utworzona (czeka na nadanie)', ['', 'pending']],
        'in_transit'       => ['W drodze',                     ['in_transit']],
        'out_for_delivery' => ['W doręczeniu',                 ['out_for_delivery']],
        'pickup'           => ['Czeka w punkcie / awizo',      ['ready_for_pickup', 'notice_left']],
        'delivered'        => ['Doręczona',                    ['delivered']],
        'problem'          => ['Problem / zwrot',              ['returned', 'issue']],
    ];

    /**
     * Czy któraś aktywna przesyłka zamówienia jest na danym etapie śledzenia (klucz z TRACKING_FILTERS).
     * Przesyłka bez danych ze śledzenia liczy się jako „Utworzona".
     */
    public static function matchesTracking(?array $shipments, string $filter): bool
    {
        $stages = self::TRACKING_FILTERS[$filter][1] ?? null;
        if ($stages === null) {
            return false;
        }
        foreach ($shipments ?? [] as $s) {
            if (in_array($s['status'] ?? '', ['cancelled', 'canceled', 'failed'], true)) { continue; }
            if (in_array((string) ($s['tracking_status'] ?? ''), $stages, true)) { return true; }
        }
        return false;
    }

    /**
     * Paragon: każde zamówienie bez faktury musi go mieć. Pokazujemy brak ("warn"), gdy klient
     * nie prosi o fakturę (ani nie podał NIP), faktury nie wystawiono, a zamówienie nie jest
     * anulowane/zwrócone. Gdy potrzebna jest faktura - paragon nie dotyczy ("muted").
     * @param array{state:string,label:string} $invoice wskaźnik faktury z forOrder()
     */
    public static function receipt(array $order, array $payload, array $invoice, bool $invoiceIssued, bool $receiptIssued): array
    {
        if ($receiptIssued) {
            return self::indicator('ok', 'receipt_issued');
        }
        if ($invoiceIssued || $invoice['state'] === 'warn') {
            return self::indicator('muted', 'receipt_not_needed');
        }
        $inactive = ['cancelled', 'canceled', 'refunded', 'failed', 'trash', 'checkout-draft'];
        $status = (string) ($payload['status'] ?? $order['status'] ?? '');
        if (in_array($status, $inactive, true) || in_array((string) ($order['pase_status'] ?? ''), $inactive, true)) {
            return self::indicator('muted', 'receipt_inactive');
        }
        return self::indicator('warn', 'receipt_missing');
    }

    /**
     * Uwaga kupującego do zamówienia: WooCommerce/TikTok/ręczne - customer_note,
     * Allegro - messageToSeller („Uwagi do zakupu”). Pusty tekst, gdy klient nic nie napisał.
     */
    public static function customerNote(array $payload): string
    {
        $note = $payload['customer_note'] ?? $payload['messageToSeller'] ?? '';
        return is_string($note) ? trim($note) : '';
    }

    /**
     * Ikona „Uwaga od klienta” na liście zamówień (null, gdy uwagi nie ma).
     * Etykieta niesie treść uwagi - widać ją po najechaniu i w okienku opisu ikon.
     */
    public static function note(array $payload): ?array
    {
        $note = self::customerNote($payload);
        if ($note === '') {
            return null;
        }
        $text = preg_replace('/\s+/u', ' ', $note) ?? $note;
        if (mb_strlen($text) > 300) {
            $text = rtrim(mb_substr($text, 0, 299)) . '…';
        }
        return ['state' => 'note', 'label' => 'Uwaga od klienta: ' . $text, 'literal' => true];
    }

    private static function indicator(string $state, string $label): array
    {
        return ['state' => $state, 'label' => 'orders.indicator.' . $label];
    }
}
