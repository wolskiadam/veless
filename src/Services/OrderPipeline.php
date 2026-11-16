<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;

/**
 * Pasek nad listą zamówień: co jest do zrobienia teraz (do realizacji, do spakowania) i gdzie są
 * wysłane paczki (czeka na nadanie, w drodze, w punkcie, problem) - zamiast liczby wszystkich
 * aktywnych zamówień, z których większość jest dawno doręczona.
 *
 * Każda liczba ma link do listy z tym samym filtrem, dlatego liczymy dokładnie tak jak filtry
 * w public/admin/_orders_query.php (status=open, need_shipping=..., date_from=...).
 */
final class OrderPipeline
{
    /** Specjalna wartość filtra statusu: wszystkie statusy „przed wysłaniem”. */
    public const OPEN = 'open';

    /** Paczki z zamówień z tylu ostatnich dni - starsze przesyłki bez śledzenia nie zaśmiecają liczb. */
    public const SHIPMENT_DAYS = 30;

    /** Kafelki przesyłek: klucz = filtr „Wysyłka” (OrderIndicators::trackingStages). */
    public const SHIPMENT_TILES = ['created', 'on_the_way', 'pickup', 'problem'];

    /**
     * Statusy „do realizacji”: systemowe Nowe i W realizacji oraz własne statusy ustawione w kolejności
     * przed „Wysłane” (Konfiguracja → Statusy zamówień). Własne za „Wysłane” (np. „Zrealizowano”) są zamknięte.
     * @param array<int,array<string,mixed>> $statusList wiersze order_statuses wg pozycji
     * @return list<string>
     */
    public static function openStatusKeys(array $statusList): array
    {
        $closed = ['shipped', 'cancelled', 'refunded'];
        $shippedPos = null;
        foreach ($statusList as $s) {
            if (($s['status_key'] ?? '') === 'shipped') { $shippedPos = (int) ($s['position'] ?? 0); }
        }
        $keys = [];
        foreach ($statusList as $s) {
            $key = (string) ($s['status_key'] ?? '');
            if ($key === '' || in_array($key, $closed, true)) { continue; }
            $open = in_array($key, ['new', 'processing'], true)
                || ($shippedPos !== null && (int) ($s['position'] ?? 0) < $shippedPos);
            if ($open) { $keys[] = $key; }
        }
        return $statusList === [] ? ['new', 'processing'] : $keys;
    }

    /** Początek okna dla kafelków przesyłek (Y-m-d) - ta sama data trafia do linku jako date_from. */
    public static function shipmentsSince(?int $now = null): string
    {
        return date('Y-m-d', strtotime('-' . self::SHIPMENT_DAYS . ' days', $now ?? time()));
    }

    /**
     * @param array<int,array<string,mixed>> $statusList
     * @return array{realize:int,pack:int,created:int,on_the_way:int,pickup:int,problem:int}
     */
    public static function counts(PDO $pdo, array $statusList, string $since): array
    {
        $out = ['realize' => 0, 'pack' => 0, 'created' => 0, 'on_the_way' => 0, 'pickup' => 0, 'problem' => 0];
        $merged = LowStock::columnExists($pdo, 'woo_orders', 'merged_into') ? ' AND wo.merged_into IS NULL' : '';
        $noShipping = LowStock::columnExists($pdo, 'woo_orders', 'no_shipping') ? ', wo.no_shipping' : '';

        try {
            // Do realizacji + do spakowania (z nich: wskaźnik wysyłki „do wysłania”, jak filtr need_shipping=1).
            $open = self::openStatusKeys($statusList);
            if ($open !== []) {
                $in = implode(',', array_fill(0, count($open), '?'));
                $stmt = $pdo->prepare("SELECT wo.woo_order_id, wo.status, wo.pase_status, wo.payload{$noShipping}
                    FROM woo_orders wo WHERE wo.lifecycle = 'active'{$merged} AND wo.pase_status IN ({$in})");
                $stmt->execute($open);
                $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $out['realize'] = count($orders);
                $shipments = self::shipmentsFor($pdo, array_map(static fn(array $o): int => (int) $o['woo_order_id'], $orders));
                foreach ($orders as $o) {
                    $payload = json_decode((string) ($o['payload'] ?? ''), true);
                    $ind = OrderIndicators::forOrder($o, is_array($payload) ? $payload : [], $shipments[(int) $o['woo_order_id']] ?? []);
                    if ($ind['shipping']['label'] === 'orders.indicator.shipping_needed') { ++$out['pack']; }
                }
            }

            // Przesyłki z zamówień z ostatnich dni, wg etapu śledzenia (jak filtr need_shipping=<etap>&date_from=...).
            $stmt = $pdo->prepare("SELECT s.woo_order_id, s.status, s.tracking_status FROM shipments s
                JOIN woo_orders wo ON wo.woo_order_id = s.woo_order_id
                WHERE wo.lifecycle = 'active'{$merged} AND wo.date_created >= ? ORDER BY s.id");
            $stmt->execute([$since . ' 00:00:00']);
            $byOrder = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
                $byOrder[(int) $s['woo_order_id']][] = $s;
            }
            foreach ($byOrder as $list) {
                foreach (self::SHIPMENT_TILES as $tile) {
                    if (OrderIndicators::matchesTracking($list, $tile)) { ++$out[$tile]; }
                }
            }
        } catch (\PDOException $e) {
            // brak tabel/kolumn (przed migracją) - pasek pokaże zera
        }
        return $out;
    }

    /** @param list<int> $ids @return array<int,list<array<string,mixed>>> */
    private static function shipmentsFor(PDO $pdo, array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $stmt = $pdo->prepare('SELECT woo_order_id, status, tracking_status, courier_code FROM shipments WHERE woo_order_id IN ('
                . implode(',', array_fill(0, count($chunk), '?')) . ') ORDER BY id');
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
                $out[(int) $s['woo_order_id']][] = $s;
            }
        }
        return $out;
    }
}
