<?php
declare(strict_types=1);

namespace Pase\Repository;

use PDO;

/**
 * Dostęp do tabeli shipments - nadane przesyłki kurierskie.
 */
final class ShipmentRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** Przesyłki dla danego zamówienia (najnowsze pierwsze). */
    public function forOrder(int $wooOrderId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM shipments WHERE woo_order_id = ? ORDER BY id DESC'
        );
        $stmt->execute([$wooOrderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM shipments WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function setStatus(int $id, string $status): void
    {
        $this->pdo->prepare('UPDATE shipments SET status = ? WHERE id = ?')->execute([$status, $id]);
    }

    /** Aktualizacja wybranych pól przesyłki (np. gdy przewoźnik asynchronicznie poda numer listu). */
    public function update(int $id, array $fields): void
    {
        $allowed = ['courier_code', 'waybill_no', 'bl_order_id', 'status', 'label_link', 'price', 'response_payload',
                    'tracking_status', 'tracking_code', 'tracking_carrier', 'tracking_at', 'tracking_checked_at', 'tracking_events'];
        $sets = [];
        $vals = [];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) {
                continue;
            }
            $sets[] = "{$k} = ?";
            $vals[] = in_array($k, ['response_payload', 'tracking_events'], true) && is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v;
        }
        if ($sets === []) {
            return;
        }
        $vals[] = $id;
        $this->pdo->prepare('UPDATE shipments SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
    }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO shipments
                (woo_order_id, integration_id, courier_code, waybill_no, bl_order_id, order_ref,
                 price, status, label_link, request_payload, response_payload)
             VALUES
                (:wo, :int, :cc, :wb, :blid, :ref, :price, :status, :label, :req, :res)'
        );
        $stmt->execute([
            ':wo'     => $data['woo_order_id'] ?? null,
            ':int'    => $data['integration_id'] ?? null,
            ':cc'     => $data['courier_code'] ?? null,
            ':wb'     => $data['waybill_no'] ?? null,
            ':blid'   => $data['bl_order_id'] ?? null,
            ':ref'    => $data['order_ref'] ?? null,
            ':price'  => $data['price'] ?? null,
            ':status' => $data['status'] ?? 'created',
            ':label'  => $data['label_link'] ?? null,
            ':req'    => isset($data['request_payload']) ? json_encode($data['request_payload'], JSON_UNESCAPED_UNICODE) : null,
            ':res'    => isset($data['response_payload']) ? json_encode($data['response_payload'], JSON_UNESCAPED_UNICODE) : null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }
}
