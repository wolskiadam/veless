<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use Pase\Repository\OrderStatusRepository;
use Pase\Repository\SettingsRepository;

/**
 * Wspólna obsługa żądań asystenta pakowania - ten sam kod dla panelu (sesja)
 * i sparowanego telefonu (token). Endpointy tylko uwierzytelniają i podają kontekst.
 */
final class PackingApi
{
    /**
     * @param array{user_id:int,user_name:string,can_edit:bool,is_admin:bool,panel:bool} $ctx
     *        panel=true: żądanie z zalogowanego panelu (może parować telefony i zmieniać ustawienia)
     * @param callable(int,string):string|null $changeStatus zmiana statusu zamówienia (z synchronizacją)
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly array $ctx,
        private readonly ?\Closure $changeStatus = null,
        private readonly ?string $pairBaseUrl = null,
    ) {}

    /**
     * @return array{0:int,1:array} [kod HTTP, odpowiedź JSON]
     *
     * Rozszerzenia: akcje ext_* (Hooks::addPackingAction), dane zamówienia przez filtr packing.order
     * (np. dodatkowe pole dla własnego skryptu) i zdarzenia packing.packed / packing.problem.
     */
    public function handle(string $action, array $in, array $files = []): array
    {
        if (str_starts_with($action, 'ext_')) {
            return $this->handleExtension($action, $in);
        }
        [$code, $body] = $this->handleCore($action, $in, $files);
        if ($code === 200 && in_array($action, ['packed', 'problem'], true) && isset($body['order'])) {
            \Pase\Plugin\Hooks::doAction('packing.' . $action, (int) ($in['id'] ?? 0), $body['order'], $this->ctx);
        }
        if (isset($body['order']) && is_array($body['order'])) {
            $body['order'] = \Pase\Plugin\Hooks::applyFilters('packing.order', $body['order'], $this->ctx);
        }
        return [$code, $body];
    }

    /** @return array{0:int,1:array} */
    private function handleExtension(string $action, array $in): array
    {
        $def = \Pase\Plugin\Hooks::packingAction($action);
        if ($def === null) {
            return [400, ['ok' => false, 'error' => 'Nieznana akcja (rozszerzenie wyłączone?).']];
        }
        if (!empty($def['write']) && !$this->ctx['can_edit']) {
            return [403, ['ok' => false, 'error' => 'Masz dostęp do pakowania tylko w trybie podglądu.']];
        }
        try {
            $res = ($def['run'])((int) ($in['id'] ?? 0), $in, $this->ctx);
            return [200, ['ok' => true] + (is_array($res) ? $res : [])];
        } catch (\RuntimeException $e) {
            return [422, ['ok' => false, 'error' => $e->getMessage()]];
        } catch (\Throwable $e) {
            \Pase\Support\Logger::warn("Rozszerzenie {$def['owner']} - akcja pakowania '{$action}': " . $e->getMessage());
            return [500, ['ok' => false, 'error' => 'Błąd rozszerzenia — szczegóły w logu.']];
        }
    }

    /** @return array{0:int,1:array} */
    private function handleCore(string $action, array $in, array $files): array
    {
        Packing::migrate($this->pdo);
        $packing = new Packing($this->pdo);
        $settings = new SettingsRepository($this->pdo);
        $by = $this->ctx['user_name'];
        $uid = $this->ctx['user_id'];
        $id = (int) ($in['id'] ?? 0);

        $writes = ['loc_assign', 'set', 'carton', 'reset', 'packed', 'problem', 'photo', 'photo_delete', 'queue_add', 'queue_remove', 'pair_code', 'revoke_device', 'settings_save'];
        if (in_array($action, $writes, true) && !$this->ctx['can_edit']) {
            return [403, ['ok' => false, 'error' => 'Masz dostęp do pakowania tylko w trybie podglądu.']];
        }
        if (in_array($action, ['pair_code', 'devices', 'revoke_device', 'settings', 'settings_save'], true) && !$this->ctx['panel']) {
            return [403, ['ok' => false, 'error' => 'To można zrobić tylko w panelu na komputerze.']];
        }

        try {
            switch ($action) {
                case 'order':
                    $order = $packing->order($id);
                    return $order === null ? [404, ['ok' => false, 'error' => 'Nie ma takiego zamówienia.']] : [200, ['ok' => true, 'order' => $order]];

                case 'orders':
                    $ids = array_slice(array_values(array_filter(array_map('intval', (array) ($in['ids'] ?? [])))), 0, 200);
                    return [200, ['ok' => true, 'orders' => $packing->summaries($ids)]];

                case 'set':
                    return [200, ['ok' => true, 'order' => $packing->setPacked($id, (int) ($in['idx'] ?? -1), (int) ($in['count'] ?? 0), $by)]];

                case 'carton':
                    return [200, ['ok' => true, 'order' => $packing->setCarton($id, (string) ($in['carton'] ?? ''), $by)]];

                case 'reset':
                    return [200, ['ok' => true, 'order' => $packing->reset($id, $by)]];

                case 'packed':
                    [$order, $msg] = $packing->markPacked($id, $by, $settings, $this->changeStatus, !empty($in['force']));
                    $packing->queueRemove($uid, $id);
                    return [200, ['ok' => true, 'order' => $order, 'message' => self::statusNote($order, $msg)]];

                case 'problem':
                    [$order, $msg] = $packing->markProblem($id, (string) ($in['note'] ?? ''), $by, $settings, $this->changeStatus);
                    return [200, ['ok' => true, 'order' => $order, 'message' => self::statusNote($order, $msg)]];

                case 'photo':
                    $file = $files['photo'] ?? null;
                    if (!is_array($file)) {
                        return [422, ['ok' => false, 'error' => 'Brak zdjęcia.']];
                    }
                    return [200, ['ok' => true, 'order' => $packing->addPhoto($id, $file, $by)]];

                case 'photo_delete':
                    return [200, ['ok' => true, 'order' => $packing->deletePhoto($id, (int) ($in['n'] ?? -1), $by)]];

                case 'find':
                    $found = $this->find(trim((string) ($in['q'] ?? '')));
                    return $found === null ? [404, ['ok' => false, 'error' => 'Nie znaleziono zamówienia.']] : [200, ['ok' => true, 'id' => $found]];

                case 'browse':
                    // Lista „Do spakowania”: telefon wybiera zamówienia sam, bez chodzenia do komputera.
                    $all = $packing->browseStatuses($settings);
                    $status = (string) ($in['status'] ?? '');
                    $pick = $status !== '' && in_array($status, $all, true) ? [$status] : $all;
                    $res = $packing->browse($pick, (string) ($in['q'] ?? ''), (int) ($in['limit'] ?? 40), (int) ($in['offset'] ?? 0));
                    $labels = [];
                    foreach ((new OrderStatusRepository($this->pdo))->all() as $s) {
                        if (in_array($s['status_key'], $all, true)) {
                            $labels[] = ['key' => $s['status_key'], 'label' => $s['label'], 'color' => $s['color'] ?? '#888'];
                        }
                    }
                    return [200, ['ok' => true, 'statuses' => $labels] + $res];

                case 'loc_scan':
                    // Skaner telefonu: zeskanowany QR półki („CRMLOC:R1-B”) albo wpisany kod.
                    $loc = (new WarehouseLocations($this->pdo))->findByCode((string) ($in['code'] ?? ''));
                    return $loc === null
                        ? [404, ['ok' => false, 'error' => 'Nie ma lokalizacji „' . WarehouseLocations::normalizeCode((string) ($in['code'] ?? '')) . '” — dodaj ją w Magazyn → Lokalizacje.']]
                        : [200, ['ok' => true, 'location' => ['id' => (int) $loc['id'], 'code' => $loc['code'], 'label' => $loc['label']]]];

                case 'loc_assign':
                    if (empty($this->ctx['can_locations'])) {
                        return [403, ['ok' => false, 'error' => 'Nie masz uprawnienia do zmiany lokalizacji (Lokalizacje w magazynie — edycja).']];
                    }
                    $res = (new WarehouseLocations($this->pdo))->assignByCode((string) ($in['code'] ?? ''), (int) ($in['location'] ?? 0));
                    return [200, ['ok' => true, 'products' => $res['products'],
                        'location' => ['id' => (int) $res['location']['id'], 'code' => $res['location']['code'], 'label' => $res['location']['label']]]];

                case 'queue':
                    return [200, ['ok' => true, 'queue' => $packing->queue($uid)]];

                case 'queue_add':
                    $ids = array_map('intval', (array) ($in['ids'] ?? []));
                    $added = $packing->queueAdd($uid, $ids);
                    return [200, ['ok' => true, 'added' => $added, 'queue' => $packing->queue($uid), 'devices' => count($packing->devices($uid))]];

                case 'queue_remove':
                    $packing->queueRemove($uid, $id);
                    return [200, ['ok' => true, 'queue' => $packing->queue($uid)]];

                case 'pair_code':
                    $code = $packing->createPairCode($uid, $id > 0 ? $id : null);
                    $url = rtrim((string) $this->pairBaseUrl, '/') . '/pack/pair.php?c=' . rawurlencode($code);
                    return [200, ['ok' => true, 'url' => $url, 'expires' => Packing::PAIR_TTL]];

                case 'devices':
                    return [200, ['ok' => true, 'devices' => $packing->devices($uid)]];

                case 'revoke_device':
                    $packing->revokeDevice($uid, (int) ($in['device'] ?? 0));
                    return [200, ['ok' => true, 'devices' => $packing->devices($uid)]];

                case 'settings':
                    return [200, ['ok' => true, 'settings' => $this->settings($settings)]];

                case 'settings_save':
                    if (!$this->ctx['is_admin']) {
                        return [403, ['ok' => false, 'error' => 'Ustawienia pakowania zmienia administrator.']];
                    }
                    $statuses = new OrderStatusRepository($this->pdo);
                    $done = (string) ($in['done_status'] ?? '');
                    $problem = (string) ($in['problem_status'] ?? '');
                    $browse = array_values(array_filter(array_map('strval', (array) ($in['browse_statuses'] ?? []))));
                    foreach ([$done, $problem, ...$browse] as $s) {
                        if ($s !== '' && !$statuses->keyExists($s)) {
                            return [422, ['ok' => false, 'error' => 'Nieznany status.']];
                        }
                    }
                    if (isset($in['cartons'])) {
                        $cartons = json_decode((string) $in['cartons'], true);
                        if (!is_array($cartons)) {
                            return [422, ['ok' => false, 'error' => 'Nieprawidłowa lista kartonów.']];
                        }
                        PackingCartons::save($settings, $cartons);   // RuntimeException → 422 z opisem
                    }
                    $settings->setMany([
                        Packing::SETTING_DONE_STATUS => $done,
                        Packing::SETTING_PROBLEM_STATUS => $problem,
                        Packing::SETTING_REQUIRE_ALL => !empty($in['require_all']) ? '1' : '0',
                        Packing::SETTING_BROWSE_STATUSES => implode(',', $browse),
                    ]);
                    return [200, ['ok' => true, 'settings' => $this->settings($settings)]];
            }
        } catch (\PDOException $e) {
            // Błąd bazy nie trafia do przeglądarki (szczegóły SQL) - tylko do logu serwera.
            error_log('packing api ' . $action . ': ' . $e->getMessage());
            return [500, ['ok' => false, 'error' => 'Błąd bazy danych — spróbuj ponownie.']];
        } catch (\DomainException $e) {
            return [409, ['ok' => false, 'error' => $e->getMessage(), 'incomplete' => true]];
        } catch (\RuntimeException $e) {
            return [422, ['ok' => false, 'error' => $e->getMessage()]];
        }
        return [400, ['ok' => false, 'error' => 'Nieznana akcja.']];
    }

    /**
     * Krótki komunikat na ekran pakowania: nowy status, a pełny opis z OrderStatusService tylko
     * wtedy, gdy wysyłka statusu do sklepu / Allegro się nie udała (to trzeba zauważyć).
     */
    private static function statusNote(array $order, ?string $msg): ?string
    {
        if ($msg === null) {
            return null;
        }
        $note = 'status: ' . $order['status']['label'];
        return str_contains($msg, 'nie powiód') || str_contains($msg, 'Nieprawidłowy') ? $note . ' (' . $msg . ')' : $note;
    }

    private function settings(SettingsRepository $settings): array
    {
        $statuses = array_map(static fn(array $s) => ['key' => $s['status_key'], 'label' => $s['label']],
            (new OrderStatusRepository($this->pdo))->all());
        return [
            'done_status'    => (string) ($settings->get(Packing::SETTING_DONE_STATUS, '') ?? ''),
            'problem_status' => (string) ($settings->get(Packing::SETTING_PROBLEM_STATUS, '') ?? ''),
            'require_all'    => ($settings->get(Packing::SETTING_REQUIRE_ALL, '1') ?? '1') === '1',
            'browse_statuses' => (new Packing($this->pdo))->browseStatuses($settings),
            'statuses'       => $statuses,
            'cartons'        => PackingCartons::load($settings),
        ];
    }

    /** Numer zamówienia (sklepu/Allegro, numer CRM albo ID) -> ID. */
    private function find(string $q): ?int
    {
        if ($q === '') {
            return null;
        }
        $q = ltrim($q, '#');
        $stmt = $this->pdo->prepare('SELECT woo_order_id FROM woo_orders
            WHERE order_number = ? OR order_number LIKE ? OR CAST(pase_number AS CHAR) = ? OR CAST(woo_order_id AS CHAR) = ?
            ORDER BY (order_number = ?) DESC, woo_order_id DESC LIMIT 1');
        $stmt->execute([$q, $q . '-%', $q, $q, $q]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }
}
