<?php
declare(strict_types=1);

namespace Pase\Services;

/**
 * Podstawianie placeholderów {{...}} w szablonach wydruku (print_templates)
 * danymi konkretnego zamówienia. Bez zależności od biblioteki PDF - dla
 * formatu A4/A5 wynik to HTML drukowany przez przeglądarkę, dla ZPL to
 * surowy tekst dla drukarek Zebra (patrz public/admin/print_document.php).
 */
final class PrintTemplateRenderer
{
    /**
     * Buduje kontekst {{zmienna}} => wartość dla konkretnego zamówienia.
     *
     * @param array<string,mixed> $row     wiersz z woo_orders (kolumny lokalnych edycji itd.)
     * @param array<string,mixed> $payload zdekodowany payload zamówienia (JSON z woo_orders)
     * @param list<array<string,mixed>> $merged wiersze zamówień dołączonych do tego (scalanie, OrderMerge::absorbed) -
     *        ich produkty i kwoty dochodzą do wydruku, bo jadą w tej samej paczce
     * @return array<string,string>
     */
    public static function contextForOrder(array $row, array $payload, string $shopName, array $merged = []): array
    {
        $billing  = $payload['billing'] ?? [];
        $shipping = $payload['shipping'] ?? [];
        $hasShipping = trim(($shipping['address_1'] ?? '') . ($shipping['city'] ?? '')) !== '';
        // Dane do faktury: poprawka z CRM (local_invoice) albo oryginał - patrz InvoiceData.
        $invoiceBilling = InvoiceData::billing($payload, isset($row['local_invoice']) ? (string) $row['local_invoice'] : null);
        $invoiceNip = (string) ($invoiceBilling['nip'] ?? '');

        $items = $row['local_items'] !== null
            ? (json_decode((string) $row['local_items'], true) ?: [])
            : ($payload['line_items'] ?? []);

        $currency = (string) ($payload['currency'] ?? $row['currency'] ?? 'PLN');
        $total = $row['local_items'] !== null
            ? array_sum(array_map(static fn($it) => (float) ($it['total'] ?? 0), $items))
            : (float) ($payload['total'] ?? $row['total'] ?? 0);
        foreach ($merged as $m) {
            $items = array_merge(array_values(is_array($items) ? $items : []), OrderMerge::items($m));
            $total += OrderMerge::orderTotal($m);
        }

        return [
            'sklep'                => $shopName,
            'numer_pase'           => $row['pase_number'] !== null ? '#' . (int) $row['pase_number'] : '—',
            'numer_sklepu'         => (string) ($row['order_number'] ?? ''),
            'data_zlozenia'        => str_replace('T', ' ', (string) ($payload['date_created'] ?? $row['date_created'] ?? '')),
            'klient_imie_nazwisko' => trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? '')),
            'klient_email'         => (string) ($row['local_billing_email'] ?? $billing['email'] ?? ''),
            'klient_telefon'       => (string) ($row['local_billing_phone'] ?? $billing['phone'] ?? ''),
            'adres_dostawy'        => self::formatAddress($hasShipping ? $shipping : $billing),
            'adres_faktury'        => self::formatAddress($invoiceBilling),
            'nip'                  => $invoiceNip,
            'waluta'               => $currency,
            'suma'                 => number_format($total, 2, ',', ' '),
            'sposob_platnosci'     => (string) ($payload['payment_method_title'] ?? ''),
            'liczba_pozycji'       => (string) count($items),
            'produkty_tabela'      => self::itemsHtml($items, $currency),
            'produkty_lista'       => self::itemsText($items, $currency),
            'scalone_zamowienia'   => implode(', ', array_map(static fn(array $m): string => OrderMerge::label($m), $merged)),
        ];
    }

    /** Przykładowe dane do podglądu szablonu w edytorze (bez wybranego zamówienia). */
    public static function sampleContext(): array
    {
        $items = [
            ['name' => 'Świeca sojowa', 'sku' => 'SW-001', 'quantity' => 2, 'price' => 25.0, 'total' => 50.0],
            ['name' => 'Knot drewniany', 'sku' => 'KN-002', 'quantity' => 1, 'price' => 5.0, 'total' => 5.0],
        ];
        return [
            'sklep'                => 'Mój Sklep',
            'numer_pase'           => '#128',
            'numer_sklepu'         => '1042',
            'data_zlozenia'        => date('Y-m-d H:i'),
            'klient_imie_nazwisko' => 'Jan Kowalski',
            'klient_email'         => 'jan.kowalski@example.com',
            'klient_telefon'       => '+48 600 000 000',
            'adres_dostawy'        => "Jan Kowalski\nul. Przykładowa 12/3\n00-001 Warszawa\nPolska",
            'adres_faktury'        => "Jan Kowalski\nul. Przykładowa 12/3\n00-001 Warszawa\nPolska",
            'nip'                  => '123-456-32-18',
            'waluta'               => 'PLN',
            'suma'                 => number_format(55.0, 2, ',', ' '),
            'sposob_platnosci'     => 'Przelew',
            'liczba_pozycji'       => (string) count($items),
            'produkty_tabela'      => self::itemsHtml($items, 'PLN'),
            'produkty_lista'       => self::itemsText($items, 'PLN'),
            'scalone_zamowienia'   => '',
        ];
    }

    /** Podstawia {{klucz}} wartościami z kontekstu. Brakujące klucze zostają puste. */
    public static function render(string $body, array $context, string $format = 'HTML'): string
    {
        $repl = [];
        foreach ($context as $k => $v) {
            $value = (string) $v;
            if ($format === 'ZPL') {
                // Order fields must not introduce printer commands.
                $value = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f\^~]/', '', $value);
            } elseif ($k !== 'produkty_tabela') {
                $value = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
            $repl['{{' . $k . '}}'] = $value;
        }
        $out = strtr($body, $repl);
        // Nierozpoznane placeholdery (literówka, usunięta zmienna) - czyścimy, żeby nie straszyły w wydruku.
        $out = (string) preg_replace('/\{\{[a-z_]+\}\}/', '', $out);
        return $format === 'ZPL' ? $out : PrintHtml::sanitize($out);
    }

    private static function formatAddress(array $a): string
    {
        $name = trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''));
        $lines = array_filter([
            $name,
            $a['company'] ?? '',
            trim(($a['address_1'] ?? '') . ' ' . ($a['address_2'] ?? '')),
            trim(($a['postcode'] ?? '') . ' ' . ($a['city'] ?? '')),
            $a['country'] ?? '',
        ], static fn($v) => trim((string) $v) !== '');
        return implode("\n", $lines);
    }

    /** @param array<int,array<string,mixed>> $items */
    private static function itemsHtml(array $items, string $currency): string
    {
        $out = '';
        foreach ($items as $it) {
            $out .= '<tr><td style="padding:5px 4px;border-bottom:1px solid #e6e3da">' . htmlspecialchars((string) ($it['name'] ?? '')) . '</td>'
                . '<td style="padding:5px 4px;border-bottom:1px solid #e6e3da">' . htmlspecialchars((string) ($it['sku'] ?? '—')) . '</td>'
                . '<td style="padding:5px 4px;border-bottom:1px solid #e6e3da;text-align:right">' . (int) ($it['quantity'] ?? 0) . '</td>'
                . '<td style="padding:5px 4px;border-bottom:1px solid #e6e3da;text-align:right">' . htmlspecialchars(number_format((float) ($it['price'] ?? 0), 2, ',', ' ')) . '</td>'
                . '<td style="padding:5px 4px;border-bottom:1px solid #e6e3da;text-align:right">' . htmlspecialchars(number_format((float) ($it['total'] ?? 0), 2, ',', ' ')) . ' ' . htmlspecialchars($currency) . '</td></tr>';
        }
        return $out;
    }

    /** @param array<int,array<string,mixed>> $items */
    private static function itemsText(array $items, string $currency): string
    {
        $lines = [];
        foreach ($items as $it) {
            $name = mb_substr((string) ($it['name'] ?? ''), 0, 28);
            $qty  = (int) ($it['quantity'] ?? 0);
            $total = number_format((float) ($it['total'] ?? 0), 2, ',', ' ');
            $lines[] = sprintf('%-28s x%-3d %8s %s', $name, $qty, $total, $currency);
        }
        return implode("\n", $lines);
    }
}
