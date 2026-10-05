<?php
declare(strict_types=1);

namespace Pase\Services;

/**
 * Sortowanie listy zamówień po kliknięciu nagłówka kolumny (?sort=<kolumna>&dir=asc|desc).
 *
 * Nazwy kolumn z GET są mapowane na gotowe wyrażenia SQL z białej listy - nic z żądania
 * nie trafia do zapytania wprost. Bez parametru lista zostaje w dotychczasowej kolejności
 * (najnowsze wg numeru PASE). Numer PASE zawsze dokłada się na końcu, żeby kolejność
 * wierszy z tą samą wartością (np. ten sam klient) była stała między stronami.
 *
 * Wymaga aliasów z listy zamówień: wo = woo_orders, ia = integration_accounts.
 * Kolumna „Dostawa” potrzebuje dodatkowo złączenia z joinSql() (przewoźnik liczony z JSON-a zamówienia).
 */
final class OrderListSort
{
    /** Kolumna => [wyrażenia SQL, domyślny kierunek przy pierwszym kliknięciu]. */
    private const COLUMNS = [
        // Numer to VARCHAR: najpierw długość, żeby 9999 było przed 10000.
        'shop'   => [['LENGTH(wo.order_number)', 'wo.order_number'], 'desc'],
        'source' => [['ia.type', 'ia.name'], 'asc'],
        // Kolejność statusów taka jak w panelu bocznym (order_statuses.position).
        'status' => [['(SELECT os.position FROM order_statuses os WHERE os.status_key = wo.pase_status LIMIT 1)', 'wo.pase_status'], 'asc'],
        'client' => [['wo.customer_name'], 'asc'],
        'email'  => [['wo.customer_email'], 'asc'],
        'amount' => [['wo.total'], 'desc'],
        // Przewoźnik (InPost, DPD...) jak ikona w kolumnie - Services\OrderChannelIcons::sortKeySql().
        'delivery' => [[], 'asc'],
        'date'   => [['wo.date_created'], 'desc'],
    ];

    /**
     * Odczytuje sortowanie z GET.
     * @return array{0: ?string, 1: string} [kolumna albo null (domyślne), kierunek asc|desc]
     */
    public static function fromRequest(array $get): array
    {
        $column = is_string($get['sort'] ?? null) ? $get['sort'] : '';
        if (!isset(self::COLUMNS[$column])) {
            return [null, 'desc'];
        }
        $dir = $get['dir'] ?? '';
        if (!in_array($dir, ['asc', 'desc'], true)) {
            $dir = self::COLUMNS[$column][1];
        }
        return [$column, $dir];
    }

    /** Fragment po ORDER BY. */
    public static function orderBy(?string $column, string $dir): string
    {
        if ($column === null || !isset(self::COLUMNS[$column])) {
            return 'wo.pase_number DESC';
        }
        $sqlDir = $dir === 'asc' ? 'ASC' : 'DESC';
        $exprs = $column === 'delivery' ? [OrderChannelIcons::sortKeySql()] : self::COLUMNS[$column][0];
        $parts = array_map(static fn(string $expr): string => $expr . ' ' . $sqlDir, $exprs);
        $parts[] = 'wo.pase_number DESC';
        return implode(', ', $parts);
    }

    /** Dodatkowe złączenie potrzebne do sortowania (po FROM/JOIN listy, przed WHERE); '' gdy niepotrzebne. */
    public static function joinSql(?string $column, string $driver): string
    {
        return $column === 'delivery' ? OrderChannelIcons::sortJoinSql($driver) : '';
    }

    /** Kierunek po kliknięciu nagłówka: ta sama kolumna odwraca, inna zaczyna od swojego domyślnego. */
    public static function nextDir(string $column, ?string $currentColumn, string $currentDir): string
    {
        if ($column === $currentColumn) {
            return $currentDir === 'asc' ? 'desc' : 'asc';
        }
        return self::COLUMNS[$column][1] ?? 'asc';
    }
}
