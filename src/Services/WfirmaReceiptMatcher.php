<?php
declare(strict_types=1);

namespace Pase\Services;

use Pase\Repository\IntegrationAccountRepository;
use Pase\Repository\OrderDocumentRepository;
use PDO;

/**
 * Synchronizacja z wFirma: paragony i faktury wystawione w wFirma poza CRM (np. przez BaseLinker) - dopasowanie
 * do zamówień i powiązanie. wFirma jest tylko czytana; powiązanie to wyłącznie wpis w order_documents
 * (jak dokument wystawiony z CRM: paragon -> receipt / receipt_fiscal, faktura -> normal).
 *
 * Dopasowanie dokumentu do zamówienia:
 *  - "sure":     numer zamówienia (Woo / Allegro / BaseLinker z meta) jest w opisie dokumentu i kwota się zgadza,
 *                albo kwota + data (do 30 dni po zamówieniu) + nazwisko kupującego lub NIP wskazują jedno zamówienie;
 *  - "probable": kwota + data wskazują dokładnie jedno zamówienie, ale nazwisko się nie potwierdza;
 *  - "ambiguous": kilka zamówień pasuje - operator wybiera.
 * Nic nie wiąże się bez kliknięcia operatora; "sure" jest tylko wstępnie zaznaczone.
 */
final class WfirmaReceiptMatcher
{
    public const MAX_DAYS = 366;
    private const DAYS_AFTER = 30;

    /** @param \Closure(array):object|null $clientFactory atrapa klienta wFirma w testach */
    public function __construct(private readonly PDO $pdo, private readonly ?\Closure $clientFactory = null) {}

    /**
     * @return array{integration_id:int,rows:list<array>,already:int,unmatched:list<array>,truncated:bool}
     */
    public function preview(string $from, string $to): array
    {
        [$from, $to] = self::range($from, $to);
        $integration = (new IntegrationAccountRepository($this->pdo))->firstActive('wfirma');
        if ($integration === null) {
            throw new \RuntimeException('Brak aktywnej integracji wFirma.');
        }
        $client = $this->clientFactory !== null
            ? ($this->clientFactory)($integration['config'])
            : new \PasePlugin\Wfirma\WfirmaClient($integration['config']);
        $found = $client->findSaleDocuments($from, $to);
        if (empty($found['ok'])) {
            throw new \RuntimeException((string) ($found['message'] ?? 'Nie udało się odczytać dokumentów z wFirma.'));
        }

        $linked = $this->pdo->query("SELECT remote_id FROM order_documents WHERE provider = 'wfirma'")->fetchAll(PDO::FETCH_COLUMN);
        $linked = array_fill_keys(array_map('strval', $linked), true);
        $receipts = array_values(array_filter($found['receipts'], static fn(array $r): bool => !isset($linked[$r['id']])));

        $local = LowStock::columnExists($this->pdo, 'woo_orders', 'local_invoice') ? 'wo.local_invoice' : 'NULL AS local_invoice';
        $stmt = $this->pdo->prepare(
            "SELECT wo.woo_order_id, wo.order_number, wo.total, wo.date_created, wo.customer_name, wo.payload, $local
             FROM woo_orders wo
             WHERE wo.date_created >= ? AND wo.date_created < ?
               AND NOT EXISTS (SELECT 1 FROM order_documents od WHERE od.woo_order_id = wo.woo_order_id
                               AND od.document_type IN ('receipt', 'receipt_fiscal', 'normal'))"
        );
        $stmt->execute([
            date('Y-m-d', strtotime($from . ' -' . self::DAYS_AFTER . ' days')) . ' 00:00:00',
            date('Y-m-d', strtotime($to . ' +2 days')) . ' 00:00:00',
        ]);
        $orders = self::withMerged($this->pdo, $stmt->fetchAll(PDO::FETCH_ASSOC));

        $result = self::match($receipts, $orders);
        return [
            'integration_id' => (int) $integration['id'],
            'rows' => $result['rows'],
            'unmatched' => $result['unmatched'],
            'already' => count($found['receipts']) - count($receipts),
            'truncated' => !empty($found['truncated']),
        ];
    }

    /**
     * Scalone zamówienia (OrderMerge) mają jeden paragon na całą paczkę: dołączone znikają z kandydatów,
     * a zamówienie główne dostaje łączną kwotę i numery dołączonych (paragon może nieść dowolny z nich).
     * @param list<array<string,mixed>> $orders
     * @return list<array<string,mixed>>
     */
    private static function withMerged(PDO $pdo, array $orders): array
    {
        if ($orders === [] || !LowStock::columnExists($pdo, 'woo_orders', 'merged_into')) {
            return $orders;
        }
        $ids = array_map(static fn(array $o): int => (int) $o['woo_order_id'], $orders);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT woo_order_id, merged_into, order_number, total, payload FROM woo_orders
            WHERE merged_into IN ($in) OR (woo_order_id IN ($in) AND merged_into IS NOT NULL) ORDER BY date_created, woo_order_id");
        $st->execute(array_merge($ids, $ids));
        $absorbedIds = [];
        $byMain = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $absorbedIds[(int) $a['woo_order_id']] = true;
            $byMain[(int) $a['merged_into']][] = $a;
        }
        $out = [];
        foreach ($orders as $o) {
            $id = (int) $o['woo_order_id'];
            if (isset($absorbedIds[$id])) {
                continue;
            }
            foreach ($byMain[$id] ?? [] as $a) {
                $o['total'] = round((float) $o['total'] + (float) $a['total'], 2);
                $o['_extra_tokens'] = array_merge($o['_extra_tokens'] ?? [], self::orderTokens($a));
                $o['merged_numbers'][] = (string) $a['order_number'];
            }
            $out[] = $o;
        }
        return $out;
    }

    /**
     * Czysta logika dopasowania (bez bazy i sieci).
     * @param list<array{id:string,number:string,type:string,date:string,total:float,buyer:string,text:string}> $receipts
     * @param list<array<string,mixed>> $orders
     * @return array{rows:list<array{receipt:array,level:string,candidates:list<array>,reason:string}>,unmatched:list<array>}
     */
    public static function match(array $receipts, array $orders): array
    {
        $prepared = array_map(static fn(array $o): array => $o + [
            '_tokens' => array_values(array_unique(array_merge(self::orderTokens($o), $o['_extra_tokens'] ?? []))),
            '_name' => self::nameTokens((string) ($o['customer_name'] ?? '')),
            '_nip' => self::orderNip($o),
            '_day' => substr((string) ($o['date_created'] ?? ''), 0, 10),
        ], $orders);

        $rows = [];
        $unmatched = [];
        foreach ($receipts as $receipt) {
            $byNumber = [];
            $byAmount = [];
            foreach ($prepared as $o) {
                $sameAmount = abs((float) $o['total'] - (float) $receipt['total']) < 0.005;
                if (self::mentions((string) $receipt['text'], $o['_tokens'])) {
                    $byNumber[] = $o + ['_amount' => $sameAmount];
                    continue;
                }
                if ($sameAmount && self::dateFits((string) $o['_day'], (string) $receipt['date'])) {
                    $byAmount[] = $o;
                }
            }

            $numberOk = array_values(array_filter($byNumber, static fn(array $o): bool => $o['_amount']));
            if (count($numberOk) === 1) {
                $rows[] = self::row($receipt, 'sure', $numberOk, 'numer zamówienia w opisie dokumentu i zgodna kwota');
                continue;
            }
            if ($byNumber !== []) {
                $rows[] = self::row($receipt, 'ambiguous', $byNumber, 'numer zamówienia w opisie, ale kwota się nie zgadza');
                continue;
            }
            if ($byAmount === []) {
                $unmatched[] = $receipt;
                continue;
            }
            $buyer = self::nameTokens((string) $receipt['buyer']);
            $nip = InvoiceData::normalizeNip((string) ($receipt['nip'] ?? ''));
            $byNip = strlen($nip) >= 10
                ? array_values(array_filter($byAmount, static fn(array $o): bool => $o['_nip'] === $nip)) : [];
            $named = $byNip !== [] ? $byNip
                : array_values(array_filter($byAmount, static fn(array $o): bool => self::sameName($o['_name'], $buyer)));
            if (count($named) === 1) {
                $rows[] = self::row($receipt, 'sure', $named, $byNip !== [] ? 'kwota, data i NIP kupującego' : 'kwota, data i nazwisko kupującego');
            } elseif (count($byAmount) === 1) {
                $rows[] = self::row($receipt, 'probable', $byAmount, 'kwota i data (nazwisko się nie potwierdza)');
            } else {
                usort($byAmount, static fn(array $a, array $b): int =>
                    self::daysBetween($a['_day'], $receipt['date']) <=> self::daysBetween($b['_day'], $receipt['date']));
                $rows[] = self::row($receipt, 'ambiguous', array_slice($named !== [] ? $named : $byAmount, 0, 8),
                    $named !== [] ? 'kilka zamówień tej osoby z tą kwotą' : 'kilka zamówień z tą kwotą i datą');
            }
        }

        // Jedno zamówienie wskazane przez kilka dokumentów nie może być "pewne" - operator rozstrzyga.
        $orderUse = [];
        foreach ($rows as $row) {
            if (count($row['candidates']) === 1) {
                $orderUse[(int) $row['candidates'][0]['woo_order_id']] = ($orderUse[(int) $row['candidates'][0]['woo_order_id']] ?? 0) + 1;
            }
        }
        foreach ($rows as &$row) {
            if (count($row['candidates']) === 1 && $orderUse[(int) $row['candidates'][0]['woo_order_id']] > 1) {
                $row['level'] = 'ambiguous';
                $row['reason'] .= '; to samo zamówienie pasuje też do innego dokumentu';
            }
        }
        unset($row);
        $rank = ['sure' => 0, 'probable' => 1, 'ambiguous' => 2];
        usort($rows, static fn(array $a, array $b): int => [$rank[$a['level']], $a['receipt']['date']] <=> [$rank[$b['level']], $b['receipt']['date']]);
        return ['rows' => $rows, 'unmatched' => $unmatched];
    }

    /**
     * Powiązuje wybrane pary. Każda para musi być wśród kandydatów bieżącego odczytu z wFirma.
     * @param array<string,int> $choices remote_id dokumentu => woo_order_id
     * @return array{linked:int,skipped:list<string>}
     */
    public function link(string $from, string $to, array $choices): array
    {
        $preview = $this->preview($from, $to);
        $documents = new OrderDocumentRepository($this->pdo);
        $linked = 0;
        $skipped = [];
        $usedOrders = [];
        foreach ($preview['rows'] as $row) {
            $receipt = $row['receipt'];
            $orderId = (int) ($choices[$receipt['id']] ?? 0);
            if ($orderId <= 0) { continue; }
            $allowed = array_map(static fn(array $o): int => (int) $o['woo_order_id'], $row['candidates']);
            if (!in_array($orderId, $allowed, true) || isset($usedOrders[$orderId])) {
                $skipped[] = $receipt['number'];
                continue;
            }
            $type = self::isInvoice($receipt['type']) ? 'normal' : (str_contains($receipt['type'], 'fiscal') ? 'receipt_fiscal' : 'receipt');
            try {
                $documents->create($orderId, $preview['integration_id'], 'wfirma', $type, $receipt['id'],
                    ($type === 'normal' ? 'Faktura ' : 'Paragon ') . $receipt['number'] . ' wystawiony poza CRM, powiązany z wFirma.');
                $usedOrders[$orderId] = true;
                $linked++;
            } catch (\PDOException) {
                $skipped[] = $receipt['number'];
            }
        }
        return ['linked' => $linked, 'skipped' => $skipped];
    }

    /** @return array{0:string,1:string} */
    public static function range(string $from, string $to): array
    {
        $valid = static fn(string $d): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
        $to = $valid($to) ? $to : date('Y-m-d');
        $from = $valid($from) ? $from : date('Y-m-d', strtotime($to . ' -30 days'));
        if ($from > $to) { [$from, $to] = [$to, $from]; }
        if (self::daysBetween($from, $to) > self::MAX_DAYS) {
            $from = date('Y-m-d', strtotime($to . ' -' . self::MAX_DAYS . ' days'));
        }
        return [$from, $to];
    }

    private static function row(array $receipt, string $level, array $candidates, string $reason): array
    {
        $clean = array_map(static fn(array $o): array => array_diff_key($o, ['payload' => 1, 'local_invoice' => 1, '_nip' => 1, '_tokens' => 1, '_extra_tokens' => 1, '_name' => 1, '_amount' => 1]), $candidates);
        return ['receipt' => $receipt, 'level' => $level, 'candidates' => array_values($clean), 'reason' => $reason];
    }

    /** Faktura (VAT, marża, rachunek), nie paragon. */
    public static function isInvoice(string $wfirmaType): bool
    {
        return !str_starts_with($wfirmaType, 'receipt');
    }

    private static function orderNip(array $order): string
    {
        $payload = json_decode((string) ($order['payload'] ?? ''), true);
        $local = isset($order['local_invoice']) && $order['local_invoice'] !== '' ? (string) $order['local_invoice'] : null;
        return InvoiceData::normalizeNip(InvoiceData::nip(is_array($payload) ? $payload : [], $local));
    }

    /** Identyfikatory zamówienia, które mogą być w opisie dokumentu. @return list<string> */
    private static function orderTokens(array $order): array
    {
        $tokens = [(string) ($order['order_number'] ?? '')];
        $payload = json_decode((string) ($order['payload'] ?? ''), true);
        if (is_array($payload)) {
            $tokens[] = (string) ($payload['id'] ?? '');
            $tokens[] = (string) ($payload['number'] ?? '');
            foreach ((array) ($payload['meta_data'] ?? []) as $meta) {
                if (is_array($meta) && stripos((string) ($meta['key'] ?? ''), 'baselinker') !== false && is_scalar($meta['value'] ?? null)) {
                    $tokens[] = (string) $meta['value'];
                }
            }
        }
        $tokens = array_map('trim', $tokens);
        // Krótkie numery (np. "12") dawałyby przypadkowe trafienia.
        return array_values(array_unique(array_filter($tokens, static fn(string $t): bool => strlen($t) >= 4)));
    }

    /** @param list<string> $tokens */
    private static function mentions(string $text, array $tokens): bool
    {
        if ($text === '') { return false; }
        foreach ($tokens as $token) {
            if (preg_match('/(?<![0-9A-Za-z])' . preg_quote($token, '/') . '(?![0-9A-Za-z])/i', $text)) {
                return true;
            }
        }
        return false;
    }

    private static function dateFits(string $orderDay, string $receiptDay): bool
    {
        if ($orderDay === '' || $receiptDay === '') { return false; }
        $diff = (strtotime($receiptDay) - strtotime($orderDay)) / 86400;
        return $diff >= -1 && $diff <= self::DAYS_AFTER;
    }

    private static function daysBetween(string $a, string $b): int
    {
        return (int) abs(round((strtotime($b) - strtotime($a)) / 86400));
    }

    /** @return list<string> */
    private static function nameTokens(string $name): array
    {
        $ascii = strtr(mb_strtolower($name), ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']);
        $parts = preg_split('/[^a-z0-9]+/', $ascii) ?: [];
        return array_values(array_filter($parts, static fn(string $p): bool => strlen($p) >= 3));
    }

    /** Co najmniej dwa wspólne człony (imię i nazwisko). Samo imię nie wystarcza. */
    private static function sameName(array $a, array $b): bool
    {
        return count(array_intersect(array_unique($a), array_unique($b))) >= 2;
    }
}
