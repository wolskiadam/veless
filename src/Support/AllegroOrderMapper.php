<?php
declare(strict_types=1);

namespace Pase\Support;

/**
 * Przekłada zamówienie z Allegro (checkout-form) na pola w formacie WooCommerce,
 * z których korzysta reszta systemu: strona zamówienia, edycja pozycji, faktura
 * wFirma, wydruki, strona klienta, automatyzacje.
 *
 * Oryginalne pola Allegro zostają nietknięte - dokładamy tylko klucze Woo
 * (line_items, total, currency, shipping_lines, payment_method_title, date_paid).
 * Pozycje biorą nazwę, ilość i cenę z oferty Allegro, więc widać, co sprzedano,
 * nawet gdy produktu nie ma w magazynie CRM.
 */
final class AllegroOrderMapper
{
    /** Czy payload to surowe zamówienie z Allegro jeszcze bez pól Woo. */
    public static function needsMapping(array $payload): bool
    {
        return isset($payload['lineItems']) && !isset($payload['line_items']);
    }

    /**
     * @param array<int,array{sku:?string,product_id:?int}> $matched dopasowanie do magazynu
     *        per indeks pozycji (z importu); brak => SKU z sygnatury oferty, product_id 0
     */
    public static function withWooFields(array $payload, array $matched = []): array
    {
        $lineItems = [];
        $itemsSum  = 0.0;
        foreach ($payload['lineItems'] ?? [] as $idx => $li) {
            $qty   = max(1, (int) ($li['quantity'] ?? 1));
            $price = (float) ($li['price']['amount'] ?? $li['originalPrice']['amount'] ?? 0);
            $total = round($price * $qty, 2);
            $itemsSum += $total;

            $sku = $matched[$idx]['sku'] ?? ($li['offer']['external']['id'] ?? null);
            $lineItems[] = [
                'id'               => $li['id'] ?? null,
                'product_id'       => (int) ($matched[$idx]['product_id'] ?? 0),
                'name'             => (string) ($li['offer']['name'] ?? 'Pozycja Allegro'),
                'sku'              => $sku !== null && $sku !== '' ? (string) $sku : null,
                'quantity'         => $qty,
                // Allegro podaje ceny brutto. Bez 'total_tax' faktura liczy brutto = total
                // i bierze domyślną stawkę VAT (patrz WfirmaDocumentService::positions).
                'price'            => $price,
                'total'            => number_format($total, 2, '.', ''),
                'allegro_offer_id' => $li['offer']['id'] ?? null,
                'in_warehouse'     => !empty($matched[$idx]['product_id']),
            ];
        }

        $shippingLines = [];
        $deliveryCost  = (float) ($payload['delivery']['cost']['amount'] ?? 0);
        if ($deliveryCost > 0) {
            $shippingLines[] = [
                'method_title' => (string) ($payload['delivery']['method']['name'] ?? 'Dostawa'),
                'total'        => number_format($deliveryCost, 2, '.', ''),
            ];
        }

        $total = $payload['summary']['totalToPay']['amount'] ?? ($itemsSum + $deliveryCost);

        $paidAt = $payload['payment']['finishedAt'] ?? null;

        return array_merge($payload, [
            'line_items'           => $lineItems,
            'shipping_lines'       => $shippingLines,
            'total'                => number_format((float) $total, 2, '.', ''),
            'currency'             => $payload['summary']['totalToPay']['currency']
                                      ?? $payload['payment']['paidAmount']['currency'] ?? 'PLN',
            'payment_method_title' => self::paymentLabel($payload['payment']['type'] ?? null,
                                                         $payload['payment']['provider'] ?? null),
            'date_paid'            => is_string($paidAt) ? str_replace('T', ' ', substr($paidAt, 0, 19)) : null,
            'date_created'         => isset($payload['lineItems'][0]['boughtAt'])
                                      ? str_replace('T', ' ', substr((string) $payload['lineItems'][0]['boughtAt'], 0, 19))
                                      : ($payload['date_created'] ?? null),
        ]);
    }

    private static function paymentLabel(?string $type, ?string $provider): string
    {
        $label = match ($type) {
            'ONLINE'           => 'Allegro — płatność online',
            'CASH_ON_DELIVERY' => 'Allegro — za pobraniem',
            'SPLIT_PAYMENT'    => 'Allegro — płatność podzielona',
            'EXTENDED_TERM'    => 'Allegro Pay — odroczona płatność',
            null, ''           => 'Allegro',
            default            => 'Allegro — ' . $type,
        };
        return $provider ? "{$label} ({$provider})" : $label;
    }
}
