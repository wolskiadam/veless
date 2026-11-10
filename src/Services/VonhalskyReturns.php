<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use Pase\Repository\IntegrationAccountRepository;
use Pase\Support\Logger;

/**
 * InPost vonHalsky: zwroty, reklamacje i zwrot pieniędzy za zamówienie.
 *
 *  - Worker (Services\Vonhalsky::run) co SYNC_EVERY_MIN pobiera zwroty (/returns) i reklamacje (/claims, ostatnie
 *    CLAIMS_DAYS dni) do vonhalsky_returns i vonhalsky_claims, połączone z zamówieniem CRM po id zamówienia vonHalsky.
 *  - Zwrot (NEW): operator przyjmuje albo odrzuca go w CRM (strona Zwroty i reklamacje albo strona zamówienia).
 *    Przyjęcie zwrotu nie zwraca pieniędzy samo - kwotę oddaje się przyciskiem „Zwróć pieniądze” (POST /orders/{id}/refund).
 *  - Reklamacja (RESOLUTION_IN_PROGRESS): uznanie z pełnym zwrotem, częściowy zwrot (procent, o który prosi klient)
 *    albo odrzucenie z komentarzem.
 *  - Zwroty i reklamacje czekające na decyzję widać w dzwoneczku (Services\Notifications) i na stronie zamówienia.
 *
 * Stanu magazynu przy zwrocie nie zmieniamy - towar przyjmuje się na magazyn jak zwykle (Zwroty w CRM), gdy dojdzie.
 */
final class VonhalskyReturns
{
    public const SYNC_EVERY_MIN = 15;
    public const CLAIMS_DAYS = 90;
    private const PAGE = 50;
    private const MAX_PAGES = 10;

    public const RETURN_LABELS = ['NEW' => 'czeka na decyzję', 'ACCEPTED' => 'przyjęty', 'REJECTED' => 'odrzucony'];
    public const CLAIM_LABELS = ['RESOLUTION_IN_PROGRESS' => 'czeka na decyzję', 'APPROVED' => 'uznana', 'REJECTED' => 'odrzucona'];
    public const RESOLUTION_LABELS = ['REFUND' => 'zwrot pieniędzy', 'PARTIAL_REFUND' => 'częściowy zwrot', 'REJECTED' => 'odrzucona'];
    public const REFUND_LABELS = ['PENDING' => 'w toku', 'SUCCESS' => 'wykonany', 'FAILED' => 'nieudany'];

    public function __construct(private readonly PDO $pdo, private readonly Vonhalsky $vh) {}

    public static function migrate(PDO $pdo): void
    {
        $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $pk = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
        $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $text = $sqlite ? 'TEXT' : 'MEDIUMTEXT';
        $pdo->exec('CREATE TABLE IF NOT EXISTS vonhalsky_returns (
            id ' . $pk . ',
            integration_id INT NOT NULL,
            return_id VARCHAR(64) NOT NULL,
            vh_order_id VARCHAR(64) NULL,
            woo_order_id BIGINT NULL,
            status VARCHAR(20) NULL,
            reason VARCHAR(500) NULL,
            tracking_number VARCHAR(64) NULL,
            created_at DATETIME NULL,
            expires_at DATETIME NULL,
            delivered_at DATETIME NULL,
            data ' . $text . ' NULL,
            action_error VARCHAR(500) NULL,
            seen_at DATETIME NULL,
            UNIQUE (integration_id, return_id)
        )' . $tail);
        $pdo->exec('CREATE TABLE IF NOT EXISTS vonhalsky_claims (
            id ' . $pk . ',
            integration_id INT NOT NULL,
            claim_id VARCHAR(64) NOT NULL,
            vh_order_id VARCHAR(64) NULL,
            woo_order_id BIGINT NULL,
            state VARCHAR(30) NULL,
            resolution VARCHAR(20) NULL,
            requested VARCHAR(20) NULL,
            claim_type VARCHAR(255) NULL,
            customer VARCHAR(255) NULL,
            created_at DATETIME NULL,
            expires_at DATETIME NULL,
            data ' . $text . ' NULL,
            action_error VARCHAR(500) NULL,
            seen_at DATETIME NULL,
            UNIQUE (integration_id, claim_id)
        )' . $tail);
        $pdo->exec('CREATE TABLE IF NOT EXISTS vonhalsky_refunds (
            id ' . $pk . ',
            integration_id INT NOT NULL,
            woo_order_id BIGINT NOT NULL,
            amount DECIMAL(12,2) NULL,
            status VARCHAR(20) NULL,
            message VARCHAR(500) NULL,
            created_by VARCHAR(120) NULL,
            created_at DATETIME NULL
        )' . $tail);
    }

    private static function sqlDate(mixed $iso): ?string
    {
        $t = is_string($iso) && $iso !== '' ? strtotime($iso) : false;
        return $t ? date('Y-m-d H:i:s', $t) : null;
    }

    private function wooId(int $accountId, string $vhOrderId): ?int
    {
        if ($vhOrderId === '') {
            return null;
        }
        $st = $this->pdo->prepare('SELECT woo_order_id FROM vonhalsky_orders WHERE integration_id = ? AND vh_order_id = ?');
        $st->execute([$accountId, $vhOrderId]);
        $id = $st->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    /** Pobiera zwroty i reklamacje (co SYNC_EVERY_MIN albo na żądanie). @return int liczba nowych zwrotów i reklamacji */
    public function sync(array $account, object $client, bool $force = false): int
    {
        $id = (int) $account['id'];
        if (!$force && time() - (int) $this->vh->state($id, 'RETURNS_AT', '0') < self::SYNC_EVERY_MIN * 60) {
            return 0;
        }
        $org = $this->vh->organizationId($account);
        $new = 0;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            [$list] = $client->returns($org, $page * self::PAGE, self::PAGE);
            foreach ($list as $r) {
                $new += $this->saveReturn($id, $r) ? 1 : 0;
            }
            if (count($list) < self::PAGE) {
                break;
            }
        }
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            [$list] = $client->claims($org, $page * self::PAGE, self::PAGE, time() - self::CLAIMS_DAYS * 86400);
            foreach ($list as $c) {
                $new += $this->saveClaim($id, $c) ? 1 : 0;
            }
            if (count($list) < self::PAGE) {
                break;
            }
        }
        (new \Pase\Repository\SettingsRepository($this->pdo))->setMany(['VONHALSKY_' . $id . '_RETURNS_AT' => (string) time()]);
        return $new;
    }

    /** @return bool true, gdy zwrot jest nowy w CRM */
    public function saveReturn(int $accountId, array $r): bool
    {
        $returnId = trim((string) ($r['id'] ?? ''));
        if ($returnId === '') {
            return false;
        }
        $vhOrder = trim((string) ($r['orderId'] ?? ''));
        $wooId = $this->wooId($accountId, $vhOrder);
        $vals = [
            $vhOrder ?: null, $wooId, strtoupper((string) ($r['status'] ?? '')) ?: null,
            mb_substr(trim((string) ($r['returnReason']['text'] ?? '')), 0, 500) ?: null,
            trim((string) ($r['trackingNumber'] ?? '')) ?: null,
            self::sqlDate($r['createdAt'] ?? null), self::sqlDate($r['expiresAt'] ?? null), self::sqlDate($r['deliveredAt'] ?? null),
            json_encode($r, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'),
        ];
        $st = $this->pdo->prepare('SELECT id FROM vonhalsky_returns WHERE integration_id = ? AND return_id = ?');
        $st->execute([$accountId, $returnId]);
        $rowId = $st->fetchColumn();
        if ($rowId !== false) {
            $this->pdo->prepare('UPDATE vonhalsky_returns SET vh_order_id = ?, woo_order_id = ?, status = ?, reason = ?, tracking_number = ?, created_at = ?,
                    expires_at = ?, delivered_at = ?, data = ?, seen_at = ? WHERE id = ?')->execute([...$vals, (int) $rowId]);
            return false;
        }
        $this->pdo->prepare('INSERT INTO vonhalsky_returns (vh_order_id, woo_order_id, status, reason, tracking_number, created_at, expires_at, delivered_at, data, seen_at,
                integration_id, return_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([...$vals, $accountId, $returnId]);
        Logger::info("vonHalsky: nowy zwrot {$returnId} (zamówienie {$vhOrder})");
        return true;
    }

    /** @return bool true, gdy reklamacja jest nowa w CRM */
    public function saveClaim(int $accountId, array $c): bool
    {
        $claimId = trim((string) ($c['claimId'] ?? ''));
        if ($claimId === '') {
            return false;
        }
        $vhOrder = trim((string) ($c['relatedOrder']['orderId'] ?? ''));
        $wooId = $this->wooId($accountId, $vhOrder);
        $who = is_array($c['claimant'] ?? null) ? trim(($c['claimant']['firstName'] ?? '') . ' ' . ($c['claimant']['lastName'] ?? '')) : '';
        $spec = is_array($c['specification'] ?? null) ? $c['specification'] : [];
        $type = trim((string) ($spec['claimTypeDescription'] ?? $spec['claimType']['description'] ?? ''));
        $vals = [
            $vhOrder ?: null, $wooId, strtoupper((string) ($c['state'] ?? '')) ?: null,
            strtoupper((string) ($c['resolution'] ?? '')) ?: null, strtoupper((string) ($spec['requestedResolution'] ?? '')) ?: null,
            mb_substr($type, 0, 255) ?: null, mb_substr($who, 0, 255) ?: null,
            self::sqlDate($c['createdAt'] ?? null), self::sqlDate($c['expiresAt'] ?? null), date('Y-m-d H:i:s'),
        ];
        $st = $this->pdo->prepare('SELECT id, data FROM vonhalsky_claims WHERE integration_id = ? AND claim_id = ?');
        $st->execute([$accountId, $claimId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            // Pełne dane (opis, pozycje) pobrane wcześniej zostają - lista daje tylko skrót.
            $old = json_decode((string) $row['data'], true);
            $data = is_array($old) ? array_replace_recursive($old, $c) : $c;
            $this->pdo->prepare('UPDATE vonhalsky_claims SET vh_order_id = ?, woo_order_id = ?, state = ?, resolution = ?, requested = ?, claim_type = ?, customer = ?,
                    created_at = ?, expires_at = ?, seen_at = ?, data = ? WHERE id = ?')->execute([...$vals, json_encode($data, JSON_UNESCAPED_UNICODE), (int) $row['id']]);
            return false;
        }
        $this->pdo->prepare('INSERT INTO vonhalsky_claims (vh_order_id, woo_order_id, state, resolution, requested, claim_type, customer, created_at, expires_at, seen_at,
                data, integration_id, claim_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([...$vals, json_encode($c, JSON_UNESCAPED_UNICODE), $accountId, $claimId]);
        Logger::info("vonHalsky: nowa reklamacja {$claimId} (zamówienie {$vhOrder})");
        return true;
    }


    // ---------------------------------------------------------------- odczyt

    /** @return array<int,array<string,mixed>> zwroty zamówienia CRM */
    public function returnsForOrder(int $wooId): array
    {
        $st = $this->pdo->prepare('SELECT * FROM vonhalsky_returns WHERE woo_order_id = ? ORDER BY created_at DESC, id DESC');
        $st->execute([$wooId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int,array<string,mixed>> reklamacje zamówienia CRM */
    public function claimsForOrder(int $wooId): array
    {
        $st = $this->pdo->prepare('SELECT * FROM vonhalsky_claims WHERE woo_order_id = ? ORDER BY created_at DESC, id DESC');
        $st->execute([$wooId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int,array<string,mixed>> zwroty pieniędzy zlecone z CRM dla zamówienia */
    public function refundsForOrder(int $wooId): array
    {
        $st = $this->pdo->prepare('SELECT * FROM vonhalsky_refunds WHERE woo_order_id = ? ORDER BY id DESC');
        $st->execute([$wooId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Pozycje zwrotu / reklamacji (nazwa, SKU) z zapisanych danych. @return list<string> */
    public static function lineNames(?string $json): array
    {
        $d = json_decode((string) $json, true);
        $out = [];
        foreach ((array) ($d['orderLines'] ?? []) as $l) {
            $name = trim((string) ($l['offer']['product']['name'] ?? ''));
            if ($name !== '') {
                $out[] = $name;
            }
        }
        $counts = array_count_values($out);
        return array_map(static fn($n, $c) => $c > 1 ? $n . ' × ' . $c : $n, array_keys($counts), $counts);
    }

    /** Pełna reklamacja (opis, żądany procent) - pobiera ją z InPost raz i zapamiętuje. */
    public function claimDetails(array $claimRow): array
    {
        $data = json_decode((string) ($claimRow['data'] ?? ''), true);
        $data = is_array($data) ? $data : [];
        if (isset($data['specification']['issueDescription']) || empty($claimRow['vh_order_id'])) {
            return $data;
        }
        $account = (new IntegrationAccountRepository($this->pdo))->find((int) $claimRow['integration_id']);
        if ($account === null) {
            return $data;
        }
        try {
            $full = $this->vh->client($account)->claim($this->vh->organizationId($account), (string) $claimRow['vh_order_id'], (string) $claimRow['claim_id']);
        } catch (\Throwable) {
            return $data;
        }
        if ($full !== []) {
            $data = array_merge($data, $full);
            $this->pdo->prepare('UPDATE vonhalsky_claims SET data = ? WHERE id = ?')->execute([json_encode($data, JSON_UNESCAPED_UNICODE), (int) $claimRow['id']]);
        }
        return $data;
    }

    // ---------------------------------------------------------------- decyzje

    private function accountFor(int $integrationId): array
    {
        $account = (new IntegrationAccountRepository($this->pdo))->find($integrationId);
        if ($account === null) {
            throw new \RuntimeException('Konto InPost vonHalsky zostało usunięte.');
        }
        return $account;
    }

    /** Przyjęcie (true) albo odrzucenie (false) zwrotu. @return array{ok:bool,message:string} */
    public function decideReturn(int $rowId, bool $accept): array
    {
        $st = $this->pdo->prepare('SELECT * FROM vonhalsky_returns WHERE id = ?');
        $st->execute([$rowId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'message' => 'Nie ma takiego zwrotu.'];
        }
        if (($row['status'] ?? '') !== 'NEW') {
            return ['ok' => false, 'message' => 'Zwrot jest już ' . (self::RETURN_LABELS[(string) $row['status']] ?? $row['status']) . '.'];
        }
        try {
            $account = $this->accountFor((int) $row['integration_id']);
            $client = $this->vh->client($account);
            $org = $this->vh->organizationId($account);
            $accept ? $client->acceptReturn($org, (string) $row['return_id']) : $client->rejectReturn($org, (string) $row['return_id']);
        } catch (\Throwable $e) {
            $this->pdo->prepare('UPDATE vonhalsky_returns SET action_error = ? WHERE id = ?')->execute([mb_substr($e->getMessage(), 0, 500), $rowId]);
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        $this->pdo->prepare('UPDATE vonhalsky_returns SET status = ?, action_error = NULL WHERE id = ?')->execute([$accept ? 'ACCEPTED' : 'REJECTED', $rowId]);
        return ['ok' => true, 'message' => $accept
            ? 'Zwrot przyjęty w InPost vonHalsky. Pieniądze oddasz przyciskiem „Zwróć pieniądze”, gdy towar dojdzie.'
            : 'Zwrot odrzucony w InPost vonHalsky.'];
    }

    /**
     * Rozstrzygnięcie reklamacji: refund (pełny zwrot), partial (częściowy - procent z żądania klienta), reject.
     * @return array{ok:bool,message:string}
     */
    public function resolveClaim(int $rowId, string $decision, string $comment = ''): array
    {
        $st = $this->pdo->prepare('SELECT * FROM vonhalsky_claims WHERE id = ?');
        $st->execute([$rowId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'message' => 'Nie ma takiej reklamacji.'];
        }
        if (($row['state'] ?? '') !== 'RESOLUTION_IN_PROGRESS') {
            return ['ok' => false, 'message' => 'Reklamacja jest już ' . (self::CLAIM_LABELS[(string) $row['state']] ?? $row['state']) . '.'];
        }
        if (!in_array($decision, ['refund', 'partial', 'reject'], true)) {
            return ['ok' => false, 'message' => 'Nieznana decyzja.'];
        }
        if ($decision === 'reject' && trim($comment) === '') {
            return ['ok' => false, 'message' => 'Przy odrzuceniu reklamacji wpisz uzasadnienie dla klienta.'];
        }
        try {
            $account = $this->accountFor((int) $row['integration_id']);
            $this->vh->client($account)->resolveClaim($this->vh->organizationId($account), (string) $row['vh_order_id'], (string) $row['claim_id'], $decision, $comment);
        } catch (\Throwable $e) {
            $this->pdo->prepare('UPDATE vonhalsky_claims SET action_error = ? WHERE id = ?')->execute([mb_substr($e->getMessage(), 0, 500), $rowId]);
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        $resolution = ['refund' => 'REFUND', 'partial' => 'PARTIAL_REFUND', 'reject' => 'REJECTED'][$decision];
        $this->pdo->prepare('UPDATE vonhalsky_claims SET state = ?, resolution = ?, action_error = NULL WHERE id = ?')
            ->execute([$decision === 'reject' ? 'REJECTED' : 'APPROVED', $resolution, $rowId]);
        return ['ok' => true, 'message' => $decision === 'reject' ? 'Reklamacja odrzucona.' : 'Reklamacja uznana - InPost zwróci klientowi ' . ($decision === 'partial' ? 'część' : 'całość') . ' kwoty.'];
    }

    /**
     * Zwrot pieniędzy za zamówienie (całość albo kwota). Pieniądze oddaje InPost - polecenie jest nieodwracalne.
     * @return array{ok:bool,message:string}
     */
    public function refundOrder(int $wooId, ?float $amount, string $user = ''): array
    {
        $link = $this->vh->orderLink($wooId);
        if ($link === null) {
            return ['ok' => false, 'message' => 'To nie jest zamówienie z InPost vonHalsky.'];
        }
        $st = $this->pdo->prepare('SELECT total, currency FROM woo_orders WHERE woo_order_id = ?');
        $st->execute([$wooId]);
        $order = $st->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'currency' => 'PLN'];
        if ($amount !== null && ($amount <= 0 || $amount > (float) $order['total'] + 0.001)) {
            return ['ok' => false, 'message' => 'Kwota zwrotu musi być większa od zera i nie większa niż wartość zamówienia (' . number_format((float) $order['total'], 2, ',', ' ') . ' zł).'];
        }
        if ($amount !== null && abs($amount - (float) $order['total']) < 0.005) {
            $amount = null;   // cała kwota - bez treści, InPost zwraca całe zamówienie
        }
        try {
            $account = $this->accountFor((int) $link['integration_id']);
            $res = $this->vh->client($account)->refundOrder($this->vh->organizationId($account), (string) $link['vh_order_id'], $amount, (string) ($order['currency'] ?: 'PLN'));
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        $status = strtoupper((string) ($res['status'] ?? 'PENDING'));
        $got = isset($res['refundAmount']['amount']) ? (float) $res['refundAmount']['amount'] : ($amount ?? (float) $order['total']);
        $this->pdo->prepare('INSERT INTO vonhalsky_refunds (integration_id, woo_order_id, amount, status, message, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([(int) $link['integration_id'], $wooId, $got, $status, mb_substr(trim((string) ($res['description'] ?? '')), 0, 500) ?: null, $user ?: null, date('Y-m-d H:i:s')]);
        if ($status === 'FAILED') {
            return ['ok' => false, 'message' => 'InPost nie wykonał zwrotu' . (trim((string) ($res['description'] ?? '')) !== '' ? ': ' . $res['description'] : '.')];
        }
        return ['ok' => true, 'message' => 'Zwrot ' . number_format($got, 2, ',', ' ') . ' zł zlecony w InPost vonHalsky (' . (self::REFUND_LABELS[$status] ?? $status) . ').'];
    }
}
