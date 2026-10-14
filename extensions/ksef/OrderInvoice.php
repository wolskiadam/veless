<?php
declare(strict_types=1);

namespace PaseExt\Ksef;

use Pase\Services\InvoiceData;
use Pase\Services\OrderMerge;
use Pase\Services\OrderPayment;
use PDO;

/**
 * Zamówienie CRM (wiersz woo_orders) -> dane faktury dla Fa3Xml.
 *
 * Pozycje i stawki VAT liczymy tak samo jak przy fakturach wFirma: pozycje poprawione w CRM (local_items)
 * mają pierwszeństwo, VAT wynika z total/total_tax pozycji sklepu, a gdy sklep go nie podał
 * (np. Allegro) - bierzemy domyślną stawkę z ustawień. Nabywca: dane do faktury z CRM albo ze sklepu.
 */
final class OrderInvoice
{
    /** Kraje UE i ich kod w numerze VAT UE (Grecja = EL). */
    private const EU = ['AT' => 'AT', 'BE' => 'BE', 'BG' => 'BG', 'CY' => 'CY', 'CZ' => 'CZ', 'DE' => 'DE', 'DK' => 'DK', 'EE' => 'EE',
        'ES' => 'ES', 'FI' => 'FI', 'FR' => 'FR', 'GR' => 'EL', 'EL' => 'EL', 'HR' => 'HR', 'HU' => 'HU', 'IE' => 'IE', 'IT' => 'IT',
        'LT' => 'LT', 'LU' => 'LU', 'LV' => 'LV', 'MT' => 'MT', 'NL' => 'NL', 'PT' => 'PT', 'RO' => 'RO', 'SE' => 'SE', 'SI' => 'SI',
        'SK' => 'SK', 'XI' => 'XI'];

    /** @param array<string,mixed> $settings ustawienia rozszerzenia */
    public function __construct(private readonly PDO $pdo, private readonly array $settings) {}

    /**
     * Dane faktury bez numeru (numer nadaje wysyłka).
     * @param array<string,mixed> $order wiersz woo_orders
     * @return array<string,mixed>
     */
    public function build(array $order, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('Europe/Warsaw'));
        if (!empty($order['merged_into'])) {
            throw new \RuntimeException('To zamówienie jest scalone z innym - wystaw e-fakturę na zamówieniu głównym, obejmie całą paczkę.');
        }
        $payload = json_decode((string) ($order['payload'] ?? ''), true) ?: [];
        $default = (string) ($this->settings['default_vat'] ?? '23');
        $exempt = ($this->settings['vat_mode'] ?? 'vat') === 'zw';

        $lines = $this->lines($this->items($order, $payload), $payload, $default);
        $numbers = [(string) ($order['order_number'] ?? $order['woo_order_id'])];
        $paid = !empty($payload['date_paid']);
        try {
            $merged = (new OrderMerge($this->pdo))->absorbedForDocument((int) $order['woo_order_id']);
        } catch (\Throwable) {
            $merged = [];   // baza bez scalania zamówień
        }
        foreach ($merged as $m) {
            $mPayload = json_decode((string) ($m['payload'] ?? ''), true) ?: [];
            $lines = array_merge($lines, $this->lines($this->items($m, $mPayload), $mPayload, $default));
            $numbers[] = (string) ($m['order_number'] ?? $m['woo_order_id']);
            $paid = $paid && !empty($mPayload['date_paid']);
        }
        if ($exempt) {
            foreach ($lines as $i => $l) {
                $lines[$i]['rate'] = 'zw';
            }
        }
        $manual = OrderPayment::state($order);
        if ($manual !== null) {
            $paid = $manual['state'] === 'ok';
        }

        $billing = InvoiceData::billing($payload, isset($order['local_invoice']) ? (string) $order['local_invoice'] : null);
        $buyer = $this->buyer($billing, $order);
        $paidDate = substr((string) ($payload['date_paid'] ?? ''), 0, 10);
        $method = (string) ($payload['payment_method'] ?? '');
        $orderDate = substr((string) ($order['date_created'] ?? $payload['date_created'] ?? ''), 0, 10);

        return [
            'issue_date' => $now->format('Y-m-d'),
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'place' => (string) ($this->settings['place'] ?? ''),
            'currency' => strtoupper((string) ($payload['currency'] ?? $order['currency'] ?? 'PLN')) ?: 'PLN',
            'system_info' => 'CRM',
            'seller' => [
                'nip' => InvoiceData::normalizeNip((string) ($this->settings['nip'] ?? '')),
                'name' => (string) ($this->settings['seller_name'] ?? ''),
                'address1' => (string) ($this->settings['seller_address1'] ?? ''),
                'address2' => (string) ($this->settings['seller_address2'] ?? ''),
                'country' => 'PL',
                'email' => (string) ($this->settings['seller_email'] ?? ''),
                'phone' => (string) ($this->settings['seller_phone'] ?? ''),
            ],
            'buyer' => $buyer,
            'lines' => $lines,
            'exempt_basis' => (string) ($this->settings['exempt_basis'] ?? ''),
            'paid' => $paid,
            'paid_date' => $paid ? (preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidDate) ? min($paidDate, $now->format('Y-m-d')) : $now->format('Y-m-d')) : null,
            'payment_form' => match ($method) { 'bacs' => 6, default => 0 },
            'payment_desc' => match ($method) {
                'bacs' => '',
                'cod' => 'Za pobraniem',
                default => mb_substr(trim((string) ($payload['payment_method_title'] ?? '')), 0, 256),
            },
            'bank_account' => $paid ? '' : (string) ($this->settings['bank_account'] ?? ''),
            'order_number' => implode(', ', array_map(static fn(string $n): string => '#' . ltrim($n, '#'), $numbers)),
            'order_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $orderDate) && $orderDate >= '1990-01-01' ? $orderDate : '',
            'footer' => (string) ($this->settings['footer'] ?? ''),
        ];
    }

    /** @return array<string,string> */
    private function buyer(array $billing, array $order): array
    {
        $country = strtoupper(trim((string) ($billing['country'] ?? ''))) ?: 'PL';
        $person = trim((string) ($billing['first_name'] ?? '') . ' ' . (string) ($billing['last_name'] ?? ''));
        $name = trim((string) ($billing['company'] ?? '')) ?: ($person ?: trim((string) ($order['customer_name'] ?? '')));
        $rawNip = strtoupper(preg_replace('/[\s.-]+/', '', (string) ($billing['nip'] ?? '')) ?? '');
        $out = [
            'nip' => '', 'eu_code' => '', 'eu_vat' => '',
            'name' => $name,
            'address1' => trim((string) ($billing['address_1'] ?? '') . ' ' . (string) ($billing['address_2'] ?? '')),
            'address2' => trim((string) ($billing['postcode'] ?? '') . ' ' . (string) ($billing['city'] ?? '')),
            'country' => preg_match('/^[A-Z]{2}$/', $country) ? $country : 'PL',
            'email' => (string) ($order['local_billing_email'] ?? $billing['email'] ?? $order['customer_email'] ?? ''),
            'phone' => (string) ($order['local_billing_phone'] ?? $billing['phone'] ?? ''),
        ];
        if ($rawNip === '') {
            return $out;
        }
        if ($country === 'PL') {
            // Błędny NIP zatrzyma wystawienie (Fa3Xml::validate) - lepiej niż faktura „bez NIP” dla firmy.
            $out['nip'] = InvoiceData::normalizeNip($rawNip);
        } elseif (isset(self::EU[$country])) {
            $code = self::EU[$country];
            $out['eu_code'] = $code;
            $out['eu_vat'] = str_starts_with($rawNip, $code) || str_starts_with($rawNip, $country) ? substr($rawNip, 2) : $rawNip;
        }
        return $out;
    }

    /** @return list<array<string,mixed>> pozycje zamówienia (poprawione w CRM albo ze sklepu) */
    private function items(array $order, array $payload): array
    {
        if (($order['local_items'] ?? null) === null || $order['local_items'] === '') {
            return array_values(array_filter((array) ($payload['line_items'] ?? []), 'is_array'));
        }
        $items = array_values(array_filter((array) (json_decode((string) $order['local_items'], true) ?: []), 'is_array'));
        // Starsze poprawki w CRM nie mają total_tax - VAT za sztukę bierzemy z oryginalnej pozycji tego samego produktu.
        $taxPerUnit = [];
        foreach ((array) ($payload['line_items'] ?? []) as $line) {
            if (is_array($line) && is_numeric($line['total_tax'] ?? null)) {
                $taxPerUnit[($line['product_id'] ?? '') . '|' . ($line['sku'] ?? '')] ??= (float) $line['total_tax'] / max(1, (int) ($line['quantity'] ?? 1));
            }
        }
        foreach ($items as $i => $item) {
            $key = ($item['product_id'] ?? '') . '|' . ($item['sku'] ?? '');
            if (!array_key_exists('total_tax', $item) && isset($taxPerUnit[$key])) {
                $items[$i]['total_tax'] = round($taxPerUnit[$key] * max(1, (int) ($item['quantity'] ?? 1)), 2);
            }
        }
        return $items;
    }

    /** @return list<array{name:string,qty:float,unit:string,unit_gross:float,gross:float,rate:string}> */
    private function lines(array $items, array $payload, string $default): array
    {
        $out = [];
        foreach ($items as $item) {
            $qty = max(1, (int) ($item['quantity'] ?? 1));
            $net = is_numeric($item['total'] ?? null) ? (float) $item['total'] : null;
            $tax = is_numeric($item['total_tax'] ?? null) ? (float) $item['total_tax'] : null;
            $gross = $net !== null ? $net + ($tax ?? 0.0) : (float) ($item['price'] ?? 0) * $qty;
            $out[] = $this->line(trim((string) ($item['name'] ?? '')) ?: 'Pozycja zamówienia', $qty, $gross, $this->rate($net, $tax, $default));
        }
        foreach (['shipping_lines' => 'Dostawa', 'fee_lines' => 'Opłata'] as $key => $fallback) {
            foreach ((array) ($payload[$key] ?? []) as $line) {
                if (!is_array($line)) {
                    continue;
                }
                $net = is_numeric($line['total'] ?? null) ? (float) $line['total'] : null;
                $tax = is_numeric($line['total_tax'] ?? null) ? (float) $line['total_tax'] : null;
                $gross = ($net ?? 0.0) + ($tax ?? 0.0);
                if (abs($gross) < 0.005) {
                    continue;
                }
                $out[] = $this->line(trim((string) ($line['method_title'] ?? $line['name'] ?? '')) ?: $fallback, 1, $gross, $this->rate($net, $tax, $default));
            }
        }
        return $out;
    }

    private function line(string $name, int $qty, float $gross, string $rate): array
    {
        $gross = round($gross, 2);
        return ['name' => $name, 'qty' => $qty, 'unit' => 'szt.', 'unit_gross' => $gross / $qty, 'gross' => $gross, 'rate' => $rate];
    }

    /** Stawka z netto i VAT pozycji (23/8/5/0); bez danych o VAT - domyślna z ustawień. */
    private function rate(?float $net, ?float $tax, string $default): string
    {
        if ($net === null || abs($net) < 0.00001 || $tax === null) {
            return $default;
        }
        $calc = $tax / $net * 100;
        foreach ([23, 8, 5, 0] as $r) {
            if (abs($calc - $r) < 0.75) {
                return (string) $r;
            }
        }
        return $default;
    }
}
