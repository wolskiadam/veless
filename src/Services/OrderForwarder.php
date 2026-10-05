<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use Pase\Automation\RuleEngine;
use Pase\Repository\IntegrationAccountRepository;
use Pase\Repository\WooOrderRepository;
use Pase\Support\Logger;
use PasePlugin\Woocommerce\WoocommerceClient;

/**
 * „Przekaż zamówienie" - tworzy kopię zamówienia w wybranym sklepie WooCommerce
 * (np. zamówienie z Allegro trafia do sklepu, żeby tam zdjąć stany / obsłużyć wysyłkę).
 *
 *  - pozycje dopasowujemy po SKU do produktów w sklepie docelowym (także wariantów);
 *    pozycja bez dopasowania idzie jako pozycja „z nazwy" z ceną,
 *  - zamówienie dostaje meta pase_source_order - import z Woo je pomija, więc w CRM
 *    nie powstaje duplikat,
 *  - numer utworzonego zamówienia zapisujemy w woo_orders.forwarded_to - drugie
 *    przekazanie do tego samego sklepu jest pomijane.
 */
final class OrderForwarder
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array{ok:bool,message:string,remote_id?:int} */
    public function toWoo(int $wooOrderId, int $integrationId): array
    {
        $acc = (new IntegrationAccountRepository($this->pdo))->find($integrationId);
        if ($acc === null || $acc['type'] !== 'woocommerce' || empty($acc['is_active'])) {
            return ['ok' => false, 'message' => 'Sklep docelowy jest niedostępny lub wyłączony.'];
        }
        $row = (new WooOrderRepository($this->pdo))->find($wooOrderId);
        if ($row === null) {
            return ['ok' => false, 'message' => 'Nie znaleziono zamówienia.'];
        }
        if ((int) ($row['integration_id'] ?? 0) === $integrationId) {
            return ['ok' => false, 'message' => 'Zamówienie pochodzi z tego sklepu — nie ma czego przekazywać.'];
        }
        $done = json_decode((string) ($row['forwarded_to'] ?? ''), true) ?: [];
        if (isset($done[(string) $integrationId])) {
            return ['ok' => true, 'message' => 'Zamówienie było już przekazane do tego sklepu (#' . $done[(string) $integrationId] . ').'];
        }

        $raw     = json_decode((string) ($row['payload'] ?? ''), true) ?: [];
        $payload = RuleEngine::normalizePayload($raw);
        $client  = new WoocommerceClient($acc['config'] ?? []);

        $items = $row['local_items'] !== null && $row['local_items'] !== ''
            ? (json_decode((string) $row['local_items'], true) ?: [])
            : ($payload['line_items'] ?? []);
        $lines = [];
        foreach ($items as $it) {
            $qty   = max(1, (int) ($it['quantity'] ?? 1));
            $total = isset($it['total']) && $it['total'] !== '' ? (float) $it['total'] + (float) ($it['total_tax'] ?? 0)
                                                                : (float) ($it['price'] ?? 0) * $qty;
            $line  = ['quantity' => $qty, 'total' => number_format($total, 2, '.', ''),
                      'subtotal' => number_format($total, 2, '.', '')];
            $match = $client->findBySku((string) ($it['sku'] ?? ''));
            if ($match !== null) {
                $line['product_id'] = $match['product_id'];
                if ($match['variation_id'] > 0) {
                    $line['variation_id'] = $match['variation_id'];
                }
            } else {
                $line['name'] = trim((string) ($it['name'] ?? '')) ?: 'Pozycja zamówienia';
                if (!empty($it['sku'])) {
                    $line['meta_data'] = [['key' => 'SKU', 'value' => (string) $it['sku']]];
                }
            }
            $lines[] = $line;
        }
        if ($lines === []) {
            return ['ok' => false, 'message' => 'Zamówienie nie ma pozycji.'];
        }

        [$billing, $shipping] = $this->addresses($raw, $payload, $row);
        $shippingLines = [];
        foreach ($payload['shipping_lines'] ?? [] as $sl) {
            $shippingLines[] = ['method_id' => 'flat_rate', 'method_title' => (string) ($sl['method_title'] ?? 'Dostawa'),
                                'total' => number_format((float) ($sl['total'] ?? 0) + (float) ($sl['total_tax'] ?? 0), 2, '.', '')];
        }

        $number = (string) ($row['pase_number'] ?: ($row['order_number'] ?? $wooOrderId));
        $body = [
            'status'         => 'processing',
            'set_paid'       => !empty($payload['date_paid']) || !empty($payload['date_paid_gmt']),
            'currency'       => (string) ($payload['currency'] ?? $row['currency'] ?? 'PLN'),
            'billing'        => $billing,
            'shipping'       => $shipping,
            'line_items'     => $lines,
            'shipping_lines' => $shippingLines,
            'customer_note'  => trim('Przekazane z CRM, zamówienie ' . $number
                . (($payload['created_via'] ?? '') === 'allegro' ? ' (Allegro ' . ($raw['id'] ?? '') . ')' : '')
                . (!empty($payload['customer_note']) ? "\n" . $payload['customer_note'] : '')),
            'meta_data'      => [['key' => 'pase_source_order', 'value' => (string) $wooOrderId]],
        ];

        $r = $client->createOrderRaw($body);
        if (!$r['ok']) {
            Logger::warn("Przekazanie zamówienia {$wooOrderId} do sklepu #{$integrationId} nie powiodło się: " . $r['message']);
            return ['ok' => false, 'message' => 'Sklep odrzucił zamówienie: ' . $r['message']];
        }
        $done[(string) $integrationId] = $r['id'];
        $this->pdo->prepare('UPDATE woo_orders SET forwarded_to = ? WHERE woo_order_id = ?')
            ->execute([json_encode($done), $wooOrderId]);
        Logger::info("Przekazano zamówienie {$wooOrderId} do sklepu #{$integrationId} jako #{$r['id']}");
        return ['ok' => true, 'message' => 'Przekazano do sklepu „' . ($acc['name'] ?? '') . '": ' . $r['message'], 'remote_id' => $r['id']];
    }

    /** @return array{0:array<string,string>,1:array<string,string>} billing, shipping w formacie Woo */
    private function addresses(array $raw, array $payload, array $row): array
    {
        if (isset($raw['buyer']) || isset($raw['delivery'])) {
            // Allegro checkout-form
            $buyer = $raw['buyer'] ?? [];
            $inv   = $raw['invoice']['address'] ?? null;
            $del   = $raw['delivery']['address'] ?? [];
            $billing = [
                'first_name' => (string) ($buyer['firstName'] ?? $del['firstName'] ?? ''),
                'last_name'  => (string) ($buyer['lastName'] ?? $del['lastName'] ?? ''),
                'company'    => (string) ($inv['company']['name'] ?? $buyer['companyName'] ?? ''),
                'address_1'  => (string) ($inv['street'] ?? $buyer['address']['street'] ?? $del['street'] ?? ''),
                'city'       => (string) ($inv['city'] ?? $buyer['address']['city'] ?? $del['city'] ?? ''),
                'postcode'   => (string) ($inv['zipCode'] ?? $buyer['address']['postCode'] ?? $del['zipCode'] ?? ''),
                'country'    => (string) ($inv['countryCode'] ?? $buyer['address']['countryCode'] ?? $del['countryCode'] ?? 'PL'),
                'email'      => (string) ($row['local_billing_email'] ?? $buyer['email'] ?? ''),
                'phone'      => (string) ($row['local_billing_phone'] ?? $buyer['phoneNumber'] ?? $del['phoneNumber'] ?? ''),
            ];
            $shipping = [
                'first_name' => (string) ($del['firstName'] ?? ''),
                'last_name'  => (string) ($del['lastName'] ?? ''),
                'company'    => (string) ($del['companyName'] ?? ''),
                'address_1'  => (string) ($del['street'] ?? ''),
                'address_2'  => !empty($raw['delivery']['pickupPoint']['id'])
                    ? 'Punkt: ' . $raw['delivery']['pickupPoint']['id'] . ' ' . ($raw['delivery']['pickupPoint']['name'] ?? '') : '',
                'city'       => (string) ($del['city'] ?? ''),
                'postcode'   => (string) ($del['zipCode'] ?? ''),
                'country'    => (string) ($del['countryCode'] ?? 'PL'),
            ];
            return [$billing, $shipping];
        }
        $billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
        if (!empty($row['local_billing_email'])) {
            $billing['email'] = $row['local_billing_email'];
        }
        if (!empty($row['local_billing_phone'])) {
            $billing['phone'] = $row['local_billing_phone'];
        }
        $shipping = is_array($payload['shipping'] ?? null) ? $payload['shipping'] : [];
        $keep = static fn(array $a) => array_map('strval', array_intersect_key($a, array_flip(
            ['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'postcode', 'country', 'state', 'email', 'phone'])));
        return [$keep($billing), $keep($shipping)];
    }
}
