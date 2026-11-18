<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;

/**
 * Licznik druku etykiet przewozowych. Każde „Drukuj (agent)”, „Drukuj (wybór)” i „Pobierz”
 * z menu „Etykieta” (label_download.php) zapisuje tu wpis: kiedy, kto, jak. Drugi druk tej samej
 * etykiety wymaga potwierdzenia (okno z datą pierwszego druku), a lista i karta zamówienia
 * pokazują plakietkę „Wydrukowano N×” (czerwona od 2) - żeby ta sama etykieta nie trafiła
 * na dwie różne paczki. Pobranie liczy się jak druk: pobrany plik zwykle idzie na drukarkę.
 */
final class LabelPrints
{
    public const MODES = ['agent' => 'agent druku', 'print' => 'druk z wyborem drukarki', 'download' => 'pobranie'];

    public function __construct(private readonly PDO $pdo) {}

    public static function migrate(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $id = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        $pdo->exec("CREATE TABLE IF NOT EXISTS shipment_label_prints (
            id $id, shipment_id BIGINT NOT NULL, woo_order_id BIGINT NULL,
            mode VARCHAR(16) NOT NULL, format VARCHAR(8) NOT NULL,
            user_id BIGINT NULL, user_name VARCHAR(190) NOT NULL,
            created_at VARCHAR(30) NOT NULL
        )" . ($driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : ''));
        if ($driver === 'mysql') {
            if (!$pdo->query("SHOW INDEX FROM shipment_label_prints WHERE Key_name = 'slp_shipment'")->fetch()) {
                $pdo->exec('CREATE INDEX slp_shipment ON shipment_label_prints (shipment_id)');
            }
            if (!$pdo->query("SHOW INDEX FROM shipment_label_prints WHERE Key_name = 'slp_order'")->fetch()) {
                $pdo->exec('CREATE INDEX slp_order ON shipment_label_prints (woo_order_id)');
            }
        } else {
            $pdo->exec('CREATE INDEX IF NOT EXISTS slp_shipment ON shipment_label_prints (shipment_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS slp_order ON shipment_label_prints (woo_order_id)');
        }
    }

    /** Zapisuje druk/pobranie. Zwraca, który to już raz dla tej przesyłki. */
    public function record(int $shipmentId, ?int $orderId, string $mode, string $format, ?int $userId, string $userName): int
    {
        self::migrate($this->pdo);
        $mode = array_key_exists($mode, self::MODES) ? $mode : 'download';
        $userName = trim($userName) !== '' ? $userName : ($userId ? 'Użytkownik' : 'System');
        $this->pdo->prepare('INSERT INTO shipment_label_prints (shipment_id, woo_order_id, mode, format, user_id, user_name, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$shipmentId, $orderId, $mode, substr($format, 0, 8), $userId, mb_substr($userName, 0, 190), gmdate('Y-m-d H:i:s')]);
        $count = $this->count($shipmentId);
        if ($orderId) {
            try {
                (new AuditTrail($this->pdo))->record($orderId, 'shipment.label_printed', [],
                    ['label' => ['shipment_id' => $shipmentId, 'mode' => self::MODES[$mode], 'format' => $format, 'count' => $count]]);
            } catch (\Throwable) {
                // Historia zmian jest dodatkiem - sam licznik już się zapisał.
            }
        }
        return $count;
    }

    public function count(int $shipmentId): int
    {
        self::migrate($this->pdo);
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM shipment_label_prints WHERE shipment_id = ?');
        $st->execute([$shipmentId]);
        return (int) $st->fetchColumn();
    }

    /**
     * Historia druku przesyłek: id => ['count' => N, 'rows' => [najstarszy … najnowszy]].
     *
     * @param int[] $shipmentIds
     * @return array<int,array{count:int,rows:array<int,array<string,mixed>>}>
     */
    public function forShipments(array $shipmentIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $shipmentIds))));
        if ($ids === []) {
            return [];
        }
        self::migrate($this->pdo);
        $st = $this->pdo->prepare('SELECT * FROM shipment_label_prints WHERE shipment_id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id');
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $sid = (int) $r['shipment_id'];
            $out[$sid] ??= ['count' => 0, 'rows' => []];
            $out[$sid]['count']++;
            $out[$sid]['rows'][] = $r;
        }
        return $out;
    }

    /**
     * Lista zamówień: najwięcej druków jednej etykiety w zamówieniu (anulowane przesyłki pomijane).
     *
     * @param int[] $orderIds
     * @return array<int,array{count:int,first:string,last:string}>
     */
    public function forOrders(array $orderIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        if ($ids === []) {
            return [];
        }
        self::migrate($this->pdo);
        $st = $this->pdo->prepare("SELECT p.woo_order_id, p.shipment_id, COUNT(*) AS n, MIN(p.created_at) AS first_at, MAX(p.created_at) AS last_at
            FROM shipment_label_prints p JOIN shipments s ON s.id = p.shipment_id
            WHERE p.woo_order_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
              AND COALESCE(s.status, '') NOT IN ('cancelled', 'canceled', 'failed')
            GROUP BY p.woo_order_id, p.shipment_id");
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $oid = (int) $r['woo_order_id'];
            if (!isset($out[$oid]) || (int) $r['n'] > $out[$oid]['count']) {
                $out[$oid] = ['count' => (int) $r['n'], 'first' => (string) $r['first_at'], 'last' => (string) $r['last_at']];
            }
        }
        return $out;
    }

    /** Czas UTC z bazy -> „18.11 o 14:02” (czas polski). */
    public static function when(string $utc): string
    {
        if ($utc === '') {
            return '';
        }
        $d = (new \DateTime($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Warsaw'));
        return $d->format('d.m') . ' o ' . $d->format('H:i');
    }

    /** Tekst plakietki, np. „Wydrukowano 2×”. */
    public static function badgeText(int $count): string
    {
        return 'Wydrukowano ' . $count . '×';
    }

    /** Dymek z historią: każdy druk w osobnej linii (najnowszy na górze). */
    public static function historyText(array $rows): string
    {
        $lines = [];
        foreach (array_reverse($rows) as $r) {
            $lines[] = self::when((string) $r['created_at']) . ' · ' . (string) $r['user_name'] . ' · ' . (self::MODES[(string) $r['mode']] ?? (string) $r['mode']);
        }
        return implode("\n", $lines);
    }

    /** Ostrzeżenie przed ponownym drukiem (to samo w oknie na karcie i na stronie ostrzeżenia). */
    public static function warning(array $info): string
    {
        $first = $info['rows'][0] ?? [];
        $last = $info['rows'] ? end($info['rows']) : [];
        $txt = 'Wydrukowano już ' . (int) $info['count'] . '×. Pierwszy raz ' . self::when((string) ($first['created_at'] ?? ''))
            . ' (' . (string) ($first['user_name'] ?? '') . ')';
        if ($info['count'] > 1) {
            $txt .= ', ostatnio ' . self::when((string) ($last['created_at'] ?? '')) . ' (' . (string) ($last['user_name'] ?? '') . ')';
        }
        return $txt . '.';
    }
}
