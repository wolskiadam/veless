<?php
declare(strict_types=1);

namespace PaseExt\Ksiegowosc;

/**
 * Skrzynka zdarzeń z CRM: jedyna droga, którą dane CRM trafiają do księgowości. Hak automation.event zapisuje tu kopię
 * zamówienia z chwili zdarzenia (payload, który CRM sam podaje), więc księgowość nie czyta tabel CRM. Kolejne etapy
 * (faktury, sprzedaż z marketplace) przetwarzają skrzynkę w swoim tempie, a po wyniesieniu modułu skrzynkę zasila
 * webhook zamiast haka.
 */
final class Inbox
{
    public const EVENTS = ['order.imported' => 'Nowe zamówienie', 'status.changed' => 'Zmiana statusu zamówienia'];
    private const MAX_PAYLOAD = 200_000;

    public function __construct(private readonly Db $db)
    {
    }

    public function record(string $event, int $orderId, array $payload): void
    {
        if (!isset(self::EVENTS[$event])) {
            return;
        }
        $json = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (strlen($json) > self::MAX_PAYLOAD) {
            $json = (string) json_encode(['_truncated' => true, 'number' => $payload['number'] ?? null, 'status' => $payload['status'] ?? null,
                'total' => $payload['total'] ?? null, 'currency' => $payload['currency'] ?? null, 'billing' => $payload['billing'] ?? null], JSON_UNESCAPED_UNICODE);
        }
        $this->db->insert('ext_ksg_inbox', ['id' => Db::id(), 'event' => $event, 'ref_type' => 'order', 'ref_id' => (string) $orderId,
            'payload' => $json, 'received_at' => Db::now(), 'processed_at' => null, 'result' => '']);
    }

    /** @return array{rows:list<array<string,mixed>>,total:int,pending:int} */
    public function list(int $limit = 50, int $offset = 0, string $event = ''): array
    {
        $where = isset(self::EVENTS[$event]) ? ' WHERE event = ?' : '';
        $args = $where !== '' ? [$event] : [];
        return [
            'rows' => $this->db->all('SELECT id, event, ref_type, ref_id, received_at, processed_at, result, payload FROM ext_ksg_inbox' . $where
                . ' ORDER BY received_at DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset), $args),
            'total' => (int) ($this->db->one('SELECT COUNT(*) AS n FROM ext_ksg_inbox' . $where, $args)['n'] ?? 0),
            'pending' => (int) ($this->db->one('SELECT COUNT(*) AS n FROM ext_ksg_inbox WHERE processed_at IS NULL')['n'] ?? 0),
        ];
    }

    /** Ile zdarzeń dotyczy zamówienia (dla Api). */
    public function countFor(int $orderId): int
    {
        return (int) ($this->db->one("SELECT COUNT(*) AS n FROM ext_ksg_inbox WHERE ref_type = 'order' AND ref_id = ?", [(string) $orderId])['n'] ?? 0);
    }
}
