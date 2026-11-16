<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use Pase\Plugin\Hooks;
use Pase\Support\PickupPoint;

/**
 * Szybki podgląd zamówienia z listy zamówień (okienko po kliknięciu oka albo dłuższym najechaniu):
 * co klient zamówił (miniatura, nazwa, wariant, ilość), dostawa i uwaga kupującego.
 *
 * Pozycje jak na karcie zamówienia: lokalna edycja w CRM ma pierwszeństwo, a zamówienia dołączone
 * przez scalanie są pokazane pod spodem z numerem zamówienia, z którego pochodzą.
 * Rozszerzenia dopisują szczegóły pozycji filtrem `order_preview.item_details`
 * (np. Zapachy - numer miejsca olejku).
 */
final class OrderPreview
{
    /** Ile pozycji pokazujemy w okienku - resztę jako „+ N więcej” (pełna lista na karcie zamówienia). */
    public const MAX_ITEMS = 12;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?OrderItemThumbnails $thumbs = null
    ) {}

    /**
     * @param array<string,mixed> $row wiersz woo_orders (+ source_type)
     * @return array<string,mixed>
     */
    public function build(array $row): array
    {
        $id = (int) $row['woo_order_id'];
        $payload = json_decode((string) ($row['payload'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        $currency = (string) ($row['currency'] ?? '');

        $items = OrderMerge::items($row);
        // Zamówienia dołączone przez scalanie: ich pozycje pod spodem, z numerem jak na liście.
        $merged = [];
        try {
            foreach ((new OrderMerge($this->pdo))->absorbed($id) as $ab) {
                foreach (OrderMerge::items($ab) as $it) {
                    $it['merged_label'] = self::number($ab);
                    $merged[] = $it;
                }
            }
        } catch (\Throwable) {
            $merged = [];
        }
        $all = array_merge($items, $merged);
        $shown = array_slice($all, 0, self::MAX_ITEMS);
        $thumbs = [];
        if ($this->thumbs !== null && $shown !== []) {
            try {
                $thumbs = $this->thumbs->forItems($shown);
            } catch (\Throwable) {
                $thumbs = [];
            }
        }

        $outItems = [];
        foreach ($shown as $i => $it) {
            $attrs = ProductionList::lineAttributes($it);
            $details = Hooks::applyFilters('order_preview.item_details', [], $it, $attrs, $row);
            $outItems[] = [
                'name' => trim((string) ($it['name'] ?? '')) ?: '—',
                'sku' => trim((string) ($it['sku'] ?? '')),
                'qty' => (float) ($it['quantity'] ?? 0) + 0,
                'total' => self::money((float) ($it['total'] ?? 0), $currency),
                'attrs' => array_map(static fn(array $a) => ['label' => $a[0], 'value' => $a[1]], $attrs),
                'details' => array_values(array_filter(is_array($details) ? $details : [], static fn($d) => is_array($d)
                    && trim((string) ($d['value'] ?? '')) !== '')),
                'thumb' => $thumbs[$i] ?? null,
                'merged_from' => isset($it['merged_label']) ? (string) $it['merged_label'] : null,
            ];
        }
        $qty = 0;
        foreach ($all as $it) {
            $qty += (float) ($it['quantity'] ?? 0);
        }

        $shipments = [];
        try {
            $st = $this->pdo->prepare('SELECT courier_code, status FROM shipments WHERE woo_order_id = ? ORDER BY id');
            $st->execute([$id]);
            $shipments = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            $shipments = [];
        }
        $delivery = OrderChannelIcons::delivery($payload, $shipments);
        $override = json_decode((string) ($row['local_pickup_point'] ?? ''), true);
        $point = PickupPoint::fromOrder($payload, is_array($override) ? $override : null);
        $note = OrderIndicators::customerNote($payload);

        return [
            'id' => $id,
            'label' => self::number($row),
            'customer' => (string) ($row['customer_name'] ?? ''),
            'total' => self::money((float) ($row['total'] ?? 0), $currency),
            'items' => $outItems,
            'more' => max(0, count($all) - count($shown)),
            'qty' => $qty + 0,
            'delivery' => [
                'name' => (string) ($delivery['name'] ?? ''),
                'method' => (string) ($delivery['method'] ?? ''),
                'point' => $point !== null ? trim(($point['id'] !== '' ? $point['id'] : '') . ($point['line'] !== '' ? ' · ' . $point['line'] : ''), ' ·') : '',
                'address' => $point === null ? self::address($payload) : '',
            ],
            'note' => $note,
            'order_url' => 'order_view.php?id=' . $id,
        ];
    }

    /** Numer zamówienia jak w kolumnie „Nr w sklepie” (Allegro: pierwszy segment UUID). */
    public static function number(array $row): string
    {
        $n = trim((string) ($row['order_number'] ?? ''));
        if ($n === '') {
            return OrderMerge::label($row);
        }
        $uuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-/i', $n) === 1;   // checkout-form Allegro (wiersze scalone nie mają source_type)
        return ($row['source_type'] ?? '') === 'allegro' || $uuid ? explode('-', $n, 2)[0] : $n;
    }

    /** Adres dostawy w jednej linii (gdy nie ma punktu odbioru). */
    private static function address(array $payload): string
    {
        $s = is_array($payload['shipping'] ?? null) ? $payload['shipping'] : [];
        if (trim((string) ($s['address_1'] ?? '')) === '' && is_array($payload['billing'] ?? null)) {
            $s = $payload['billing'];
        }
        $street = trim(trim((string) ($s['address_1'] ?? '')) . ' ' . trim((string) ($s['address_2'] ?? '')));
        $city = trim(trim((string) ($s['postcode'] ?? '')) . ' ' . trim((string) ($s['city'] ?? '')));
        return trim($street . ($street !== '' && $city !== '' ? ', ' : '') . $city);
    }

    private static function money(float $v, string $currency): string
    {
        return trim(number_format($v, 2, ',', "\u{00a0}") . "\u{00a0}" . $currency);
    }
}
