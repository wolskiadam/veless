<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;

/**
 * Scalanie zamówień: klient dokupił coś osobnym zamówieniem, a wysyłamy wszystko w jednej paczce.
 *
 * Scalenie dzieje się tylko w CRM. Zamówienia w WooCommerce i na Allegro zostają nietknięte:
 *  - zamówienie główne dostaje dołączone zamówienia (woo_orders.merged_into = ID głównego),
 *  - dołączone zamówienie NIE jest usuwane ani anulowane - zostaje na liście, wyszarzone, z odnośnikiem
 *    do głównego; stan magazynu i stan faktyczny odjęły już oba zamówienia przy imporcie, więc scalenie
 *    niczego w magazynie nie zmienia (nic nie schodzi drugi raz i nic nie wraca),
 *  - pozycje i kwoty nie są kopiowane: strona zamówienia, pakowanie i wydruki doliczają je z dołączonych
 *    zamówień w locie (extraItems / groupTotal), dlatego cofnięcie scalenia to tylko wyczyszczenie merged_into,
 *  - jeden dokument na paczkę: faktura / paragon wystawia się na zamówieniu głównym i obejmuje pozycje
 *    dołączonych (WfirmaDocumentService, absorbedForDocument); paragon z BaseLinkera dopasowuje się do łącznej
 *    kwoty (WfirmaReceiptMatcher); dołączone bez własnego dokumentu pokazuje dokumenty głównego,
 *  - zmiana statusu głównego (np. „Wysłane”) przechodzi na dołączone razem z synchronizacją do kanału,
 *    ale bez reguł automatyzacji, żeby klient nie dostał dwa razy tego samego e-maila.
 *
 * Scalać można tylko aktywne zamówienia, które jeszcze nie wyszły: bez nadanej przesyłki i nie w statusie
 * wysłane / anulowane / zwrot. Inny klient, inny adres dostawy, punkt odbioru, waluta albo pobranie
 * dają ostrzeżenie, które trzeba potwierdzić.
 */
final class OrderMerge
{
    /** Statusy CRM, w których zamówienie już wyszło albo nie będzie wysyłane. */
    public const CLOSED_STATUSES = ['shipped', 'cancelled', 'refunded'];
    /** Statusy głównego, których NIE przenosimy na dołączone (anulowanie ma być świadome, per zamówienie). */
    private const NOT_PROPAGATED = ['cancelled', 'refunded'];

    public function __construct(private readonly PDO $pdo) {}

    public static function migrate(PDO $pdo): void
    {
        if (!LowStock::columnExists($pdo, 'woo_orders', 'merged_into')) {
            $pdo->exec('ALTER TABLE woo_orders ADD COLUMN merged_into BIGINT NULL');
            try {
                $pdo->exec('CREATE INDEX idx_woo_orders_merged_into ON woo_orders (merged_into)');
            } catch (\Throwable) {
                // indeks to tylko przyspieszenie
            }
        }
        if (!LowStock::columnExists($pdo, 'woo_orders', 'merged_at')) {
            $pdo->exec('ALTER TABLE woo_orders ADD COLUMN merged_at DATETIME NULL');
        }
    }

    // ------------------------------------------------------------
    //  Sprawdzenie i scalenie
    // ------------------------------------------------------------

    /**
     * Sprawdza, czy zamówienia $sourceIds można dołączyć do $targetId.
     * @param int[] $sourceIds
     * @return array{errors:list<string>,warnings:list<string>,orders:array<int,array>}
     */
    public function check(int $targetId, array $sourceIds): array
    {
        $sourceIds = array_values(array_unique(array_filter(array_map('intval', $sourceIds), static fn(int $i): bool => $i > 0 && $i !== $targetId)));
        $errors = [];
        $warnings = [];
        $orders = $this->load(array_merge([$targetId], $sourceIds));
        if (!isset($orders[$targetId])) {
            return ['errors' => ['Nie znaleziono zamówienia głównego.'], 'warnings' => [], 'orders' => $orders];
        }
        if ($sourceIds === []) {
            $errors[] = 'Wybierz co najmniej jedno zamówienie do dołączenia.';
        }
        $shipments = $this->activeShipments(array_keys($orders));
        foreach (array_merge([$targetId], $sourceIds) as $id) {
            $o = $orders[$id] ?? null;
            if ($o === null) {
                $errors[] = "Nie znaleziono zamówienia #{$id}.";
                continue;
            }
            $label = self::label($o);
            if (($o['lifecycle'] ?? 'active') !== 'active') {
                $errors[] = "Zamówienie {$label} jest w archiwum albo w koszu - przywróć je, zanim je scalisz.";
            }
            if (in_array((string) ($o['pase_status'] ?? ''), self::CLOSED_STATUSES, true)) {
                $errors[] = "Zamówienie {$label} jest już wysłane, anulowane albo zwrócone.";
            }
            if (!empty($shipments[$id])) {
                $errors[] = "Zamówienie {$label} ma już nadaną przesyłkę. Anuluj ją albo scal inne zamówienia do tego.";
            }
            if ($id === $targetId && !empty($o['merged_into'])) {
                $errors[] = "Zamówienie {$label} jest już dołączone do innego - scalaj do tamtego zamówienia.";
            }
            if ($id !== $targetId && !empty($o['merged_into']) && (int) $o['merged_into'] !== $targetId) {
                $errors[] = "Zamówienie {$label} jest już dołączone do innego zamówienia. Najpierw je odłącz.";
            }
        }
        if ($errors !== []) {
            return ['errors' => array_values(array_unique($errors)), 'warnings' => [], 'orders' => $orders];
        }

        $target = $orders[$targetId];
        $tKeys = array_column(Customers::keysFor($target), 'k');
        $tAddr = self::addressKey($target);
        $tPoint = self::pickupPointId($target);
        foreach ($sourceIds as $id) {
            $o = $orders[$id];
            $label = self::label($o);
            $sameCustomer = array_intersect($tKeys, array_column(Customers::keysFor($o), 'k')) !== []
                || ($tKeys === [] && self::norm((string) $o['customer_name']) !== '' && self::norm((string) $o['customer_name']) === self::norm((string) $target['customer_name']));
            if (!$sameCustomer) {
                $warnings[] = "Zamówienie {$label} wygląda na zamówienie innego klienta ("
                    . ($o['customer_name'] ?: 'bez nazwiska') . ' / ' . ($target['customer_name'] ?: 'bez nazwiska') . ').';
            }
            $addr = self::addressKey($o);
            if ($tAddr !== '' && $addr !== '' && $addr !== $tAddr) {
                $warnings[] = "Zamówienie {$label} ma inny adres dostawy niż zamówienie główne - paczka pójdzie na adres głównego.";
            }
            $point = self::pickupPointId($o);
            if ($point !== $tPoint) {
                $warnings[] = "Zamówienie {$label} ma inny punkt odbioru" . ($point !== '' ? " ({$point})" : ' (brak)')
                    . ' niż zamówienie główne' . ($tPoint !== '' ? " ({$tPoint})" : ' (brak)') . ' - paczka pójdzie do punktu głównego.';
            }
            try {
                $doc = $this->pdo->prepare("SELECT COUNT(*) FROM order_documents WHERE woo_order_id = ? AND document_type IN ('normal', 'receipt', 'receipt_fiscal')");
                $doc->execute([$id]);
                if ((int) $doc->fetchColumn() > 0) {
                    $warnings[] = "Zamówienie {$label} ma już własną fakturę lub paragon - nie wejdzie do dokumentu zamówienia głównego.";
                }
            } catch (\PDOException) {
                // brak tabeli dokumentów
            }
            if (strtoupper((string) ($o['currency'] ?: 'PLN')) !== strtoupper((string) ($target['currency'] ?: 'PLN'))) {
                $warnings[] = "Zamówienie {$label} jest w innej walucie ({$o['currency']}) - suma będzie orientacyjna.";
            }
        }
        foreach (array_merge([$targetId], $sourceIds) as $id) {
            $payload = self::payload($orders[$id]);
            $pay = OrderIndicators::forOrder($orders[$id], $payload, [])['payment'];
            // Po etykiecie, nie po kolorze ikony (nieopłacone też jest czerwone - patrz OrderIndicators::COLORS).
            if (str_ends_with($pay['label'], 'payment_failed')) {
                $warnings[] = 'Zamówienie ' . self::label($orders[$id]) . ' ma nieudaną płatność.';
            } elseif (in_array($pay['state'], ['warn', 'bad', 'partial'], true)) {
                $warnings[] = 'Zamówienie ' . self::label($orders[$id]) . (str_ends_with($pay['label'], 'payment_cod')
                    ? ' jest za pobraniem - przy nadawaniu wpisz kwotę pobrania za całą paczkę.'
                    : ' nie jest jeszcze opłacone.');
            }
        }
        return ['errors' => [], 'warnings' => array_values(array_unique($warnings)), 'orders' => $orders];
    }

    /**
     * Dołącza zamówienia do głównego. Zamówienia dołączone wcześniej do dołączanego przechodzą do głównego.
     * @param int[] $sourceIds
     * @return int liczba dołączonych zamówień
     */
    public function merge(int $targetId, array $sourceIds, bool $acceptWarnings): int
    {
        $sourceIds = array_values(array_unique(array_filter(array_map('intval', $sourceIds), static fn(int $i): bool => $i > 0 && $i !== $targetId)));
        $audit = new AuditTrail($this->pdo);
        return $audit->atomic(function () use ($targetId, $sourceIds, $acceptWarnings, $audit): int {
            // Blokada wierszy (MySQL), żeby dwa kliknięcia naraz nie scaliły krzyżowo.
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $ids = array_merge([$targetId], $sourceIds);
                $in = implode(',', array_fill(0, count($ids), '?'));
                $this->pdo->prepare("SELECT woo_order_id FROM woo_orders WHERE woo_order_id IN ($in) FOR UPDATE")->execute($ids);
            }
            $check = $this->check($targetId, $sourceIds);
            if ($check['errors'] !== []) {
                throw new \RuntimeException(implode(' ', $check['errors']));
            }
            if ($check['warnings'] !== [] && !$acceptWarnings) {
                throw new \RuntimeException('Potwierdź ostrzeżenia: ' . implode(' ', $check['warnings']));
            }
            $now = gmdate('Y-m-d H:i:s');
            $set = $this->pdo->prepare('UPDATE woo_orders SET merged_into = ?, merged_at = ? WHERE woo_order_id = ?');
            $reparent = $this->pdo->prepare('UPDATE woo_orders SET merged_into = ? WHERE merged_into = ?');
            $count = 0;
            foreach ($sourceIds as $id) {
                if ((int) ($check['orders'][$id]['merged_into'] ?? 0) === $targetId) {
                    continue;   // już dołączone do tego samego
                }
                $reparent->execute([$targetId, $id]);
                $set->execute([$targetId, $now, $id]);
                $label = self::label($check['orders'][$id]);
                $audit->record($id, 'order.merged', [], ['merged_into' => $targetId]);
                $audit->record($targetId, 'order.merged', [], ['absorbed' => $id]);
                $count++;
                \Pase\Support\Logger::info("Scalanie: zamówienie {$label} (#{$id}) dołączone do #{$targetId}");
            }
            return $count;
        });
    }

    /** Odłącza zamówienie od głównego - wraca jako osobne zamówienie. */
    public function unmerge(int $sourceId): ?int
    {
        $audit = new AuditTrail($this->pdo);
        return $audit->atomic(function () use ($sourceId, $audit): ?int {
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $st = $this->pdo->prepare('SELECT merged_into FROM woo_orders WHERE woo_order_id = ?' . $lock);
            $st->execute([$sourceId]);
            $target = $st->fetchColumn();
            if ($target === false || $target === null) {
                return null;
            }
            $this->pdo->prepare('UPDATE woo_orders SET merged_into = NULL, merged_at = NULL WHERE woo_order_id = ?')->execute([$sourceId]);
            $audit->record($sourceId, 'order.unmerged', ['merged_into' => (int) $target], []);
            $audit->record((int) $target, 'order.unmerged', ['absorbed' => $sourceId], []);
            return (int) $target;
        });
    }

    // ------------------------------------------------------------
    //  Odczyt: strona zamówienia, lista, pakowanie, wydruki
    // ------------------------------------------------------------

    /** Zamówienia dołączone do głównego (pełne wiersze woo_orders), od najstarszego. */
    public function absorbed(int $targetId): array
    {
        $st = $this->pdo->prepare('SELECT * FROM woo_orders WHERE merged_into = ? ORDER BY date_created, woo_order_id');
        $st->execute([$targetId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Zamówienia dołączone do $mainId, które wchodzą do dokumentu (faktura / paragon) zamówienia głównego:
     * jeden dokument na całą paczkę. Pomija dołączone, które mają już własny wystawiony dokument sprzedaży
     * (np. paragon sprzed scalenia) - inaczej sprzedaż byłaby w wFirma dwa razy.
     */
    public function absorbedForDocument(int $mainId): array
    {
        $out = [];
        $has = $this->pdo->prepare("SELECT COUNT(*) FROM order_documents WHERE woo_order_id = ? AND document_type IN ('normal', 'receipt', 'receipt_fiscal')");
        foreach ($this->absorbed($mainId) as $row) {
            $has->execute([(int) $row['woo_order_id']]);
            if ((int) $has->fetchColumn() === 0) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /** Wiersz zamówienia głównego, do którego dołączono $id (albo null). */
    public function targetOf(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT t.* FROM woo_orders s JOIN woo_orders t ON t.woo_order_id = s.merged_into WHERE s.woo_order_id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Pozycje dołączonych zamówień do dopisania pod pozycjami głównego (pakowanie, wydruki, strona zamówienia).
     * Każda ma merged_from (ID zamówienia) i merged_label (numer do pokazania).
     */
    public function extraItems(int $targetId): array
    {
        $out = [];
        foreach ($this->absorbed($targetId) as $row) {
            foreach (self::items($row) as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $it['merged_from'] = (int) $row['woo_order_id'];
                $it['merged_label'] = self::label($row);
                $out[] = $it;
            }
        }
        return $out;
    }

    /** Pozycje zamówienia: lokalna edycja w CRM, a bez niej oryginał ze sklepu. */
    public static function items(array $row): array
    {
        if (($row['local_items'] ?? null) !== null && $row['local_items'] !== '') {
            $items = json_decode((string) $row['local_items'], true);
        } else {
            $items = self::payload($row)['line_items'] ?? [];
        }
        return array_values(array_filter(is_array($items) ? $items : [], 'is_array'));
    }

    /** Suma zamówienia tak, jak pokazuje ją strona zamówienia (lokalna edycja = suma pozycji). */
    public static function orderTotal(array $row): float
    {
        if (($row['local_items'] ?? null) !== null && $row['local_items'] !== '') {
            return array_sum(array_map(static fn($it): float => is_array($it) ? (float) ($it['total'] ?? 0) : 0.0, self::items($row)));
        }
        $payload = self::payload($row);
        return (float) ($payload['total'] ?? $row['total'] ?? 0);
    }

    /**
     * Na liście zamówień: dla każdego ID z bieżącej strony - do czego dołączono / ile dołączono i suma.
     * @param int[] $ids
     * @return array<int,array{into?:array{id:int,label:string},absorbed?:int,numbers?:list<string>,group_total?:float}> numbers = numery dołączonych, group_total = cała paczka (z głównym)
     */
    public function listInfo(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        try {
            $st = $this->pdo->prepare("SELECT s.woo_order_id, t.woo_order_id AS tid, t.pase_number, t.order_number, t.integration_id
                FROM woo_orders s JOIN woo_orders t ON t.woo_order_id = s.merged_into WHERE s.woo_order_id IN ($in)");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int) $r['woo_order_id']]['into'] = ['id' => (int) $r['tid'], 'label' => self::label(['woo_order_id' => $r['tid']] + $r)];
            }
            $st = $this->pdo->prepare("SELECT merged_into, woo_order_id, pase_number, order_number, payload, local_items, total FROM woo_orders WHERE merged_into IN ($in) ORDER BY date_created, woo_order_id");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $t = (int) $r['merged_into'];
                $out[$t]['absorbed'] = ($out[$t]['absorbed'] ?? 0) + 1;
                $num = (string) ($r['order_number'] ?? '');
                $out[$t]['numbers'][] = $num !== '' ? explode('-', $num, 2)[0] : self::label($r);
                $out[$t]['group_total'] = ($out[$t]['group_total'] ?? 0.0) + self::orderTotal($r);
            }
            $targets = array_keys(array_filter($out, static fn(array $i): bool => !empty($i['absorbed'])));
            if ($targets !== []) {
                $st = $this->pdo->prepare('SELECT woo_order_id, payload, local_items, total FROM woo_orders WHERE woo_order_id IN ('
                    . implode(',', array_fill(0, count($targets), '?')) . ')');
                $st->execute($targets);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $out[(int) $r['woo_order_id']]['group_total'] += self::orderTotal($r);
                }
            }
        } catch (\PDOException) {
            return [];   // przed migracją kolumny merged_into
        }
        return $out;
    }

    /**
     * Status zmieniony na zamówieniu głównym: te same statusy dostają dołączone (bez anulowania i zwrotu).
     * Zwraca ID zamówień do przestawienia.
     * @return int[]
     */
    public function propagationTargets(int $targetId, string $newStatus): array
    {
        if (in_array($newStatus, self::NOT_PROPAGATED, true)) {
            return [];
        }
        try {
            $st = $this->pdo->prepare('SELECT woo_order_id FROM woo_orders WHERE merged_into = ? AND (pase_status IS NULL OR pase_status <> ?)');
            $st->execute([$targetId, $newStatus]);
            return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (\PDOException) {
            return [];
        }
    }

    // ------------------------------------------------------------

    /** Krótki numer do pokazania: #numer CRM, a bez niego numer ze sklepu / Allegro. */
    public static function label(array $row): string
    {
        if (!empty($row['pase_number'])) {
            return '#' . (int) $row['pase_number'];
        }
        $num = (string) ($row['order_number'] ?? '');
        if ($num !== '') {
            return explode('-', $num, 2)[0];
        }
        return '#' . (int) ($row['woo_order_id'] ?? 0);
    }

    /** @param int[] $ids @return array<int,array> */
    private function load(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->pdo->prepare("SELECT * FROM woo_orders WHERE woo_order_id IN ($in)");
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['woo_order_id']] = $r;
        }
        return $out;
    }

    /** @param int[] $ids @return array<int,int> liczba aktywnych przesyłek na zamówienie */
    private function activeShipments(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        try {
            $st = $this->pdo->prepare("SELECT woo_order_id, status FROM shipments WHERE woo_order_id IN ($in)");
            $st->execute($ids);
        } catch (\PDOException) {
            return [];
        }
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
            if (!in_array((string) ($s['status'] ?? ''), ['cancelled', 'canceled', 'failed', 'error'], true)) {
                $out[(int) $s['woo_order_id']] = ($out[(int) $s['woo_order_id']] ?? 0) + 1;
            }
        }
        return $out;
    }

    private static function payload(array $row): array
    {
        $p = is_array($row['payload'] ?? null) ? $row['payload'] : json_decode((string) ($row['payload'] ?? ''), true);
        return is_array($p) ? $p : [];
    }

    /** Adres dostawy znormalizowany do porównania (ulica + kod), pusty = nie wiadomo. */
    private static function addressKey(array $row): string
    {
        $p = self::payload($row);
        if (isset($p['delivery']) && is_array($p['delivery'])) {
            $a = $p['delivery']['address'] ?? [];
            $street = $a['street'] ?? '';
            $zip = $a['zipCode'] ?? '';
        } else {
            $a = is_array($p['shipping'] ?? null) && !empty($p['shipping']['address_1']) ? $p['shipping'] : ($p['billing'] ?? []);
            $street = trim(($a['address_1'] ?? '') . ' ' . ($a['address_2'] ?? ''));
            $zip = $a['postcode'] ?? '';
        }
        $street = self::norm((string) $street);
        $zip = preg_replace('/\D+/', '', (string) $zip) ?? '';
        return $street === '' && $zip === '' ? '' : $zip . '|' . $street;
    }

    private static function pickupPointId(array $row): string
    {
        $local = json_decode((string) ($row['local_pickup_point'] ?? ''), true);
        $pp = \Pase\Support\PickupPoint::fromOrder(self::payload($row), is_array($local) ? $local : null);
        return is_array($pp) ? strtoupper(trim((string) ($pp['id'] ?? ''))) : '';
    }

    private static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        return preg_replace('/[^\p{L}\p{N}]+/u', '', $s) ?? '';
    }
}
