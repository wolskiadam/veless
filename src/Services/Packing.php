<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use Pase\Repository\SettingsRepository;
use Pase\Support\AdminSession;

/**
 * Asystent pakowania: co jest w zamówieniu, ile już spakowano, zdjęcia paczki,
 * kolejka „na telefon” i sparowane telefony (stanowiska pakowania).
 *
 * Postęp trzymamy na serwerze (tabela packing_orders), więc komputer i telefon
 * widzą to samo - zapis idzie pozycja po pozycji (set idx = liczba), więc dwa
 * urządzenia pakujące naraz nie nadpisują sobie całego zamówienia.
 *
 * Telefon łączy się bez hasła: w panelu powstaje jednorazowy kod (QR, ważny 10 min),
 * telefon go otwiera i dostaje długi losowy token w ciasteczku. W bazie trzymamy tylko
 * skrót tokenu oraz „odcisk” konta (AdminSession::stamp) - zmiana hasła, roli czy
 * wyłączenie konta automatycznie odcina telefon. Token daje dostęp wyłącznie do pakowania.
 */
final class Packing
{
    public const PAIR_TTL = 600;             // ważność kodu QR (s)
    public const DEVICE_COOKIE = 'CRM_PACK';
    public const DEVICE_DAYS = 180;          // jak długo telefon zostaje sparowany bez użycia

    public const SETTING_DONE_STATUS = 'PACKING_DONE_STATUS';
    public const SETTING_PROBLEM_STATUS = 'PACKING_PROBLEM_STATUS';
    public const SETTING_REQUIRE_ALL = 'PACKING_REQUIRE_ALL';
    /** Statusy zamówień, które pokazuje lista „Do spakowania” (klucze po przecinku; puste = domyślne). */
    public const SETTING_BROWSE_STATUSES = 'PACKING_BROWSE_STATUSES';
    /** Domyślnie nie pokazujemy zamówień wysłanych, anulowanych i zwróconych. */
    private const NOT_TO_PACK = ['shipped', 'cancelled', 'refunded', 'completed'];

    private const PHOTO_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic'];

    public function __construct(private readonly PDO $pdo) {}

    // ------------------------------------------------------------------ schemat

    public static function migrate(PDO $pdo): void
    {
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $autoId = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $pdo->exec("CREATE TABLE IF NOT EXISTS packing_orders (
            woo_order_id BIGINT NOT NULL PRIMARY KEY,
            progress TEXT NULL,
            state VARCHAR(16) NOT NULL DEFAULT 'open',
            note TEXT NULL,
            photos TEXT NULL,
            updated_at DATETIME NULL,
            updated_by VARCHAR(190) NULL,
            packed_at DATETIME NULL,
            packed_by VARCHAR(190) NULL,
            carton VARCHAR(40) NULL
        ){$engine}");
        try {
            $pdo->query('SELECT carton FROM packing_orders LIMIT 1');
        } catch (\PDOException) {
            $pdo->exec('ALTER TABLE packing_orders ADD COLUMN carton VARCHAR(40) NULL');   // karton wybrany przy pakowaniu
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS packing_devices (
            id {$autoId},
            user_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            user_stamp CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            last_seen_at DATETIME NULL,
            revoked_at DATETIME NULL
        ){$engine}");
        $pdo->exec("CREATE TABLE IF NOT EXISTS packing_pair_codes (
            code_hash CHAR(64) NOT NULL PRIMARY KEY,
            user_id INT NOT NULL,
            woo_order_id BIGINT NULL,
            expires_at INT NOT NULL,
            used_at DATETIME NULL
        ){$engine}");
        $pdo->exec("CREATE TABLE IF NOT EXISTS packing_queue (
            user_id INT NOT NULL,
            woo_order_id BIGINT NOT NULL,
            added_at DATETIME NOT NULL,
            PRIMARY KEY (user_id, woo_order_id)
        ){$engine}");
        try {
            WarehouseLocations::migrate($pdo);   // plakietki lokalizacji w asystencie
        } catch (\PDOException) {
            // brak tabeli products (np. świeża instalacja) - lokalizacje po prostu się nie pokażą
        }
    }

    // ------------------------------------------------------------------ zamówienie

    /** Dane zamówienia do asystenta albo null, gdy nie istnieje. */
    public function order(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM woo_orders WHERE woo_order_id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $o = json_decode((string) ($row['payload'] ?? ''), true);
        $o = is_array($o) ? $o : [];
        $rawItems = ($row['local_items'] ?? null) !== null
            ? (json_decode((string) $row['local_items'], true) ?: [])
            : ($o['line_items'] ?? []);
        $rawItems = array_values(array_filter(is_array($rawItems) ? $rawItems : [], 'is_array'));

        // Scalanie (OrderMerge): główne pakuje też produkty dołączonych zamówień (indeksy po własnych),
        // a dołączone nie ma czego pakować - jedzie w paczce głównego.
        $mergeNote = '';
        if (array_key_exists('merged_into', $row)) {
            $merge = new OrderMerge($this->pdo);
            if (!empty($row['merged_into'])) {
                $target = $merge->targetOf($id);
                $rawItems = [];
                $mergeNote = 'Scalone z zamówieniem ' . ($target !== null ? OrderMerge::label($target) : '#' . (int) $row['merged_into'])
                    . ' - produkty pakujesz w tamtym zamówieniu.';
            } else {
                $extra = $merge->extraItems($id);
                if ($extra !== []) {
                    $rawItems = array_merge($rawItems, $extra);
                    $mergeNote = 'Scalone: w tej paczce także zamówienia '
                        . implode(', ', array_values(array_unique(array_column($extra, 'merged_label')))) . '.';
                }
            }
        }

        $thumbs = (new OrderItemThumbnails($this->pdo))->forItems($rawItems);
        // woo_orders nie ma kolumny z typem kanału (lista bierze go z integracji) - Allegro poznajemy po payloadzie.
        $isAllegro = isset($o['buyer']) || isset($o['delivery']) || isset($o['lineItems']);
        $state = $this->state($id);
        $items = [];
        foreach ($rawItems as $i => $it) {
            $qty = max(0, (int) ($it['quantity'] ?? 0));
            if ($qty === 0) {
                continue;
            }
            $items[] = [
                'idx'    => $i,
                'name'   => (string) ($it['name'] ?? 'Produkt'),
                'qty'    => $qty,
                'sku'    => (string) ($it['sku'] ?? ''),
                'ean'    => $this->ean($it),
                'image'  => $thumbs[$i] ?? null,
                'attrs'  => trim(self::attributes($it) . (isset($it['merged_label']) ? ' | z zamówienia ' . $it['merged_label'] : ''), ' |'),
                'packed' => min($qty, max(0, (int) ($state['progress'][$i] ?? 0))),
                'location' => null,
            ];
        }

        // Lokalizacje w magazynie (Magazyn → Lokalizacje): plakietka „📍 R1-B” i kolejność
        // pozycji wg trasy po magazynie; pozycje bez lokalizacji na końcu, reszta bez zmian.
        $hasLocations = false;
        try {
            $hasLocations = (new WarehouseLocations($this->pdo))->count() > 0;
            if ($hasLocations && $items !== []) {
                $bySku = $this->locationsBySku(array_column($items, 'sku'));
                foreach ($items as &$item) {
                    $item['location'] = $bySku[strtolower($item['sku'])] ?? null;
                }
                unset($item);
                $pos = array_flip(array_keys($items));
                usort($items, static function (array $a, array $b) use ($pos): int {
                    $ka = $a['location'] !== null ? [0, $a['location']['sort'], $a['location']['code']] : [1, 0, ''];
                    $kb = $b['location'] !== null ? [0, $b['location']['sort'], $b['location']['code']] : [1, 0, ''];
                    return $ka <=> $kb ?: $a['idx'] <=> $b['idx'];
                });
            }
        } catch (\PDOException) {
            $hasLocations = false;
        }

        $number = (string) ($row['order_number'] ?? $id);
        // Numer Allegro to długi UUID checkout-formu - jak na liście zamówień pokazujemy pierwszy segment.
        $shortNumber = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-/i', $number) ? explode('-', $number, 2)[0] : $number;
        $shipping = $isAllegro ? ($o['delivery']['method']['name'] ?? '') : ($o['shipping_lines'][0]['method_title'] ?? '');
        // Punkt odbioru (paczkomat...) - pakujący widzi, dokąd idzie paczka.
        $point = \Pase\Support\PickupPoint::fromOrder($o, json_decode((string) ($row['local_pickup_point'] ?? ''), true) ?: null);
        if ($point !== null) {
            $shipping = trim($shipping . ' · punkt ' . $point['id']);
        }
        $note = $isAllegro ? ($o['messageToSeller'] ?? '') : ($o['customer_note'] ?? '');
        $statuses = [];
        try {
            foreach ($this->pdo->query('SELECT status_key, label, color FROM order_statuses') as $s) {
                $statuses[$s['status_key']] = $s;
            }
        } catch (\PDOException) {
        }
        $st = $statuses[$row['pase_status'] ?? ''] ?? null;

        return [
            'id'        => $id,
            'number'    => $shortNumber,
            'crmNumber' => $row['pase_number'] ?? null,
            'customer'  => (string) ($row['customer_name'] ?? ''),
            'shipping'  => (string) $shipping,
            'note'      => trim($mergeNote . "\n" . trim((string) $note)),
            'status'    => ['key' => (string) ($row['pase_status'] ?? ''), 'label' => $st['label'] ?? (string) ($row['pase_status'] ?? ''),
                            'color' => $st['color'] ?? '#888888'],
            'state'     => $state['state'],
            'problem'   => $state['note'],
            'packedAt'  => $state['packed_at'],
            'packedBy'  => $state['packed_by'],
            'updatedAt' => $state['updated_at'],
            'items'     => $items,
            'hasLocations' => $hasLocations,
            'carton'    => $this->cartonInfo($rawItems, $isAllegro || ($row['status'] ?? '') === 'manual', $state['carton']),
            'photos'    => array_map(static fn(array $p, int $n) => ['n' => $n, 'at' => $p['at'] ?? null, 'by' => $p['by'] ?? null],
                               $state['photos'], array_keys($state['photos'])),
        ];
    }

    /** @return array{progress:array<int,int>,state:string,note:?string,photos:list<array>,packed_at:?string,packed_by:?string,updated_at:?string} */
    public function state(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM packing_orders WHERE woo_order_id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $progress = [];
        foreach ((array) (json_decode((string) ($r['progress'] ?? ''), true) ?: []) as $k => $v) {
            $progress[(int) $k] = (int) $v;
        }
        $photos = json_decode((string) ($r['photos'] ?? ''), true);
        return [
            'progress'   => $progress,
            'state'      => (string) ($r['state'] ?? 'open'),
            'note'       => isset($r['note']) && $r['note'] !== '' ? (string) $r['note'] : null,
            'photos'     => is_array($photos) ? array_values(array_filter($photos, 'is_array')) : [],
            'packed_at'  => $r['packed_at'] ?? null,
            'packed_by'  => $r['packed_by'] ?? null,
            'updated_at' => $r['updated_at'] ?? null,
            'carton'     => isset($r['carton']) && $r['carton'] !== '' ? (string) $r['carton'] : null,
        ];
    }

    /** Ustawia liczbę spakowanych sztuk jednej pozycji (przycięte do 0..ilość). */
    public function setPacked(int $id, int $idx, int $count, string $by): array
    {
        $order = $this->order($id);
        if ($order === null) {
            throw new \RuntimeException('Nie ma takiego zamówienia.');
        }
        $qty = null;
        foreach ($order['items'] as $it) {
            if ($it['idx'] === $idx) {
                $qty = $it['qty'];
            }
        }
        if ($qty === null) {
            throw new \RuntimeException('Nie ma takiej pozycji w zamówieniu.');
        }
        $state = $this->state($id);
        $state['progress'][$idx] = max(0, min($qty, $count));
        // Zmiana ilości po „Spakowano” / „Nie spakowano” wraca zamówienie do pakowania.
        $this->save($id, $state['progress'], 'open', null, $state['photos'], $by, null, null);
        return $this->order($id);
    }

    /** Karton wybrany przez pakującego ('' = wróć do sugestii). */
    public function setCarton(int $id, string $name, string $by): array
    {
        $this->order($id) ?? throw new \RuntimeException('Nie ma takiego zamówienia.');
        $name = trim($name);
        if ($name !== '' && !in_array($name, array_column(PackingCartons::load(new SettingsRepository($this->pdo)), 'name'), true)) {
            throw new \RuntimeException('Nie ma takiego kartonu — odśwież ekran.');
        }
        $state = $this->state($id);
        $this->save($id, $state['progress'], $state['state'], $state['note'], $state['photos'], $by, $state['packed_at'], $state['packed_by']);
        $this->pdo->prepare('UPDATE packing_orders SET carton = ? WHERE woo_order_id = ?')->execute([$name !== '' ? $name : null, $id]);
        return $this->order($id);
    }

    /**
     * „Gabaryt paczki”: lista kartonów z ustawień, sugestia z wymiarów i wagi produktów z magazynu
     * oraz karton wybrany ręcznie. Null, gdy lista kartonów jest pusta.
     * @param bool $crmIds product_id w pozycji to ID produktu w CRM (Allegro, zamówienia ręczne), a nie ID w sklepie
     */
    private function cartonInfo(array $rawItems, bool $crmIds, ?string $chosen): ?array
    {
        try {
            $cartons = PackingCartons::load(new SettingsRepository($this->pdo));
        } catch (\PDOException) {
            return null;
        }
        if ($cartons === []) {
            return null;
        }
        $items = [];
        foreach ($rawItems as $it) {
            $qty = max(0, (int) ($it['quantity'] ?? 0));
            if ($qty === 0) {
                continue;
            }
            $p = $this->productDims($it, $crmIds);
            $items[] = ['name' => (string) ($it['name'] ?? 'Produkt'), 'qty' => $qty,
                'dims' => $p !== null && $p['length'] !== null && $p['width'] !== null && $p['height'] !== null
                    ? [$p['length'], $p['width'], $p['height']] : null,
                'weight' => $p['weight'] ?? null];
        }
        $res = PackingCartons::suggest($items, $cartons);
        $res['chosen'] = $chosen !== null && in_array($chosen, array_column($cartons, 'name'), true) ? $chosen : null;
        return $res;
    }

    /**
     * Wymiary (cm) i waga (kg) produktu z magazynu dla pozycji: najpierw SKU, potem ID
     * (w CRM dla Allegro / ręcznych, ID wariantu i produktu w sklepie dla WooCommerce).
     * @return array{length:?float,width:?float,height:?float,weight:?float}|null
     */
    private function productDims(array $it, bool $crmIds): ?array
    {
        $q = function (string $where, array $args): ?array {
            try {
                $s = $this->pdo->prepare("SELECT length, width, height, weight FROM products WHERE $where LIMIT 1");
                $s->execute($args);
                $r = $s->fetch(PDO::FETCH_ASSOC);
            } catch (\PDOException) {
                return null;
            }
            if (!$r) {
                return null;
            }
            $f = static fn($v) => $v !== null && $v !== '' && (float) $v > 0 ? (float) $v : null;
            return ['length' => $f($r['length']), 'width' => $f($r['width']), 'height' => $f($r['height']), 'weight' => $f($r['weight'])];
        };
        $sku = trim((string) ($it['sku'] ?? ''));
        if ($sku !== '' && ($r = $q('sku = ?', [$sku]))) {
            return $r;
        }
        $pid = (int) ($it['product_id'] ?? 0);
        if ($crmIds) {
            return $pid > 0 ? $q('id = ?', [$pid]) : null;
        }
        $vid = (int) ($it['variation_id'] ?? 0);
        if ($vid > 0) {
            return $q('woo_variation_id = ?', [$vid]);
        }
        return $pid > 0 ? $q('woo_product_id = ? AND (woo_variation_id IS NULL OR woo_variation_id = 0)', [$pid]) : null;
    }

    /** Czyści postęp (kosz w asystencie). Zdjęcia zostają. */
    public function reset(int $id, string $by): array
    {
        $state = $this->state($id);
        $this->save($id, [], 'open', null, $state['photos'], $by, null, null);
        return $this->order($id) ?? throw new \RuntimeException('Nie ma takiego zamówienia.');
    }

    /** Czy wszystkie sztuki spakowane. */
    public static function complete(array $order): bool
    {
        foreach ($order['items'] as $it) {
            if ($it['packed'] < $it['qty']) {
                return false;
            }
        }
        return $order['items'] !== [];
    }

    /**
     * „Spakowano”: znacznik + zmiana statusu z ustawień (przez OrderStatusService - z synchronizacją
     * do sklepu/Allegro i automatyzacjami). Zwraca [zamówienie, komunikat o statusie|null].
     *
     * @param callable(int,string):string|null $changeStatus
     */
    public function markPacked(int $id, string $by, SettingsRepository $settings, ?callable $changeStatus, bool $force = false): array
    {
        $order = $this->order($id) ?? throw new \RuntimeException('Nie ma takiego zamówienia.');
        if (!$force && ($settings->get(self::SETTING_REQUIRE_ALL, '1') ?? '1') === '1' && !self::complete($order)) {
            throw new \DomainException('Nie wszystko jest spakowane.');
        }
        $state = $this->state($id);
        $this->save($id, $state['progress'], 'packed', null, $state['photos'], $by, gmdate('Y-m-d H:i:s'), $by);
        $msg = $this->applyStatus($id, (string) ($settings->get(self::SETTING_DONE_STATUS, '') ?? ''), $changeStatus);
        return [$this->order($id), $msg];
    }

    /** „Nie spakowano”: powód + opcjonalny status z ustawień. */
    public function markProblem(int $id, string $note, string $by, SettingsRepository $settings, ?callable $changeStatus): array
    {
        $this->order($id) ?? throw new \RuntimeException('Nie ma takiego zamówienia.');
        $state = $this->state($id);
        $note = mb_substr(trim($note), 0, 500);
        $this->save($id, $state['progress'], 'problem', $note !== '' ? $note : 'Nie spakowano', $state['photos'], $by, null, null);
        $msg = $this->applyStatus($id, (string) ($settings->get(self::SETTING_PROBLEM_STATUS, '') ?? ''), $changeStatus);
        return [$this->order($id), $msg];
    }

    private function applyStatus(int $id, string $status, ?callable $changeStatus): ?string
    {
        if ($status === '' || $changeStatus === null) {
            return null;
        }
        $current = $this->pdo->prepare('SELECT pase_status FROM woo_orders WHERE woo_order_id = ?');
        $current->execute([$id]);
        if ((string) $current->fetchColumn() === $status) {
            return null;
        }
        return $changeStatus($id, $status);
    }

    // ------------------------------------------------------------------ zdjęcia paczki

    /** Zapisuje zdjęcie z formularza ($_FILES) i dopina do zamówienia. */
    public function addPhoto(int $id, array $file, string $by): array
    {
        $this->order($id) ?? throw new \RuntimeException('Nie ma takiego zamówienia.');
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, self::PHOTO_EXT, true)) {
            // Aparat w telefonie potrafi wysłać plik bez rozszerzenia - poznajemy po typie.
            $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic']
                [(string) ($file['type'] ?? '')] ?? '';
            if ($ext === '') {
                throw new \RuntimeException('To nie jest zdjęcie.');
            }
        }
        if (($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new \RuntimeException('Nie udało się przesłać zdjęcia.');
        }
        if ((int) ($file['size'] ?? 0) > 15 * 1024 * 1024) {
            throw new \RuntimeException('Zdjęcie jest za duże (max 15 MB).');
        }
        $dir = self::photoDir();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Nie można utworzyć katalogu na zdjęcia.');
        }
        $name = $id . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!@move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $name)) {
            throw new \RuntimeException('Nie udało się zapisać zdjęcia.');
        }
        return $this->attachPhoto($id, $name, $by);
    }

    /** Dopina zapisany już plik (wydzielone dla testów). */
    public function attachPhoto(int $id, string $fileName, string $by): array
    {
        $state = $this->state($id);
        $state['photos'][] = ['file' => $fileName, 'at' => gmdate('Y-m-d H:i:s'), 'by' => $by];
        $this->save($id, $state['progress'], $state['state'], $state['note'], $state['photos'], $by,
            $state['packed_at'], $state['packed_by']);
        return $this->order($id);
    }

    /** Usuwa zdjęcie nr $n (z listy i z dysku). Kolejne zdjęcia przesuwają się o jedno miejsce. */
    public function deletePhoto(int $id, int $n, string $by): array
    {
        $this->order($id) ?? throw new \RuntimeException('Nie ma takiego zamówienia.');
        $state = $this->state($id);
        if (!isset($state['photos'][$n])) {
            throw new \RuntimeException('Nie ma takiego zdjęcia — odśwież ekran.');
        }
        $path = $this->photoPath($id, $n);
        array_splice($state['photos'], $n, 1);
        $this->save($id, $state['progress'], $state['state'], $state['note'], $state['photos'], $by,
            $state['packed_at'], $state['packed_by']);
        if ($path !== null) {
            @unlink($path);
        }
        return $this->order($id);
    }

    /** Ścieżka pliku zdjęcia nr $n albo null. */
    public function photoPath(int $id, int $n): ?string
    {
        $file = (string) ($this->state($id)['photos'][$n]['file'] ?? '');
        if ($file === '' || !preg_match('/^[0-9]+-[a-f0-9]{16}\.[a-z]{3,4}$/D', $file)) {
            return null;
        }
        $path = self::photoDir() . '/' . $file;
        return is_file($path) ? $path : null;
    }

    public static function photoDir(): string
    {
        return (defined('PASE_ROOT') ? PASE_ROOT : dirname(__DIR__, 2)) . '/storage/uploads/packing';
    }

    public static function streamPhoto(string $path): never
    {
        $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'heic' => 'image/heic'];
        header('Content-Type: ' . ($types[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    // ------------------------------------------------------------------ lista „Do spakowania”

    /**
     * Statusy, które trafiają na listę „Do spakowania”: z ustawień, a gdy puste - wszystkie
     * oprócz wysłanych/anulowanych/zwróconych i statusu ustawianego po „Spakowano”.
     * @return list<string>
     */
    public function browseStatuses(SettingsRepository $settings): array
    {
        $all = [];
        try {
            $all = array_map('strval', $this->pdo->query('SELECT status_key FROM order_statuses ORDER BY position, id')->fetchAll(PDO::FETCH_COLUMN));
        } catch (\PDOException) {
        }
        $saved = array_values(array_filter(array_map('trim', explode(',', (string) ($settings->get(self::SETTING_BROWSE_STATUSES, '') ?? '')))));
        if ($saved !== []) {
            return array_values(array_intersect($all, $saved));
        }
        $done = (string) ($settings->get(self::SETTING_DONE_STATUS, '') ?? '');
        return array_values(array_filter($all, static fn(string $k) => !in_array($k, self::NOT_TO_PACK, true) && $k !== $done));
    }

    /**
     * Zamówienia do spakowania (najstarsze pierwsze, spakowane na końcu) - lekka lista bez zdjęć.
     * @param list<string> $statuses
     * @return array{orders:list<array>,total:int}
     */
    public function browse(array $statuses, string $q = '', int $limit = 40, int $offset = 0): array
    {
        if ($statuses === []) {
            return ['orders' => [], 'total' => 0];
        }
        $where = ["w.lifecycle = 'active'", 'w.pase_status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')'];
        $hasMerge = LowStock::columnExists($this->pdo, 'woo_orders', 'merged_into');
        if ($hasMerge) {
            $where[] = 'w.merged_into IS NULL';   // dołączone jadą w paczce głównego (OrderMerge)
        }
        $args = array_values($statuses);
        $q = trim(ltrim($q, '#'));
        if ($q !== '') {
            $where[] = '(w.order_number LIKE ? OR w.customer_name LIKE ? OR CAST(w.pase_number AS CHAR) = ?)';
            array_push($args, '%' . $q . '%', '%' . $q . '%', $q);
        }
        $sqlWhere = implode(' AND ', $where);
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM woo_orders w WHERE {$sqlWhere}");
        $count->execute($args);
        $stmt = $this->pdo->prepare("SELECT w.woo_order_id, w.order_number, w.pase_number, w.customer_name, w.pase_status, w.date_created,
                w.local_items, w.payload, p.state, p.progress
            FROM woo_orders w LEFT JOIN packing_orders p ON p.woo_order_id = w.woo_order_id
            WHERE {$sqlWhere}
            ORDER BY CASE WHEN p.state = 'packed' THEN 1 ELSE 0 END, w.date_created, w.woo_order_id
            LIMIT " . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset));
        $stmt->execute($args);
        $out = [];
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $extra = [];
        if ($hasMerge && $rows !== []) {
            $ids = array_map(static fn(array $r): int => (int) $r['woo_order_id'], $rows);
            $ab = $this->pdo->prepare('SELECT merged_into, payload, local_items FROM woo_orders WHERE merged_into IN ('
                . implode(',', array_fill(0, count($ids), '?')) . ')');
            $ab->execute($ids);
            foreach ($ab->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $extra[(int) $a['merged_into']] = array_merge($extra[(int) $a['merged_into']] ?? [], OrderMerge::items($a));
            }
        }
        foreach ($rows as $r) {
            $payload = json_decode((string) ($r['payload'] ?? ''), true);
            $items = ($r['local_items'] ?? null) !== null ? (json_decode((string) $r['local_items'], true) ?: [])
                : (is_array($payload) ? ($payload['line_items'] ?? []) : []);
            $items = array_merge(is_array($items) ? $items : [], $extra[(int) $r['woo_order_id']] ?? []);
            $total = 0;
            $lines = 0;
            $first = '';
            foreach (is_array($items) ? $items : [] as $it) {
                $qty = is_array($it) ? max(0, (int) ($it['quantity'] ?? 0)) : 0;
                if ($qty > 0) {
                    $total += $qty;
                    $lines++;
                    $first = $first !== '' ? $first : (string) ($it['name'] ?? '');
                }
            }
            $progress = json_decode((string) ($r['progress'] ?? ''), true);
            $packed = is_array($progress) ? array_sum(array_map('intval', $progress)) : 0;
            $number = (string) ($r['order_number'] ?? $r['woo_order_id']);
            $out[] = [
                'id'       => (int) $r['woo_order_id'],
                'number'   => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-/i', $number) ? explode('-', $number, 2)[0] : $number,
                'customer' => (string) ($r['customer_name'] ?? ''),
                'status'   => (string) ($r['pase_status'] ?? ''),
                'date'     => $r['date_created'] ?? null,
                'packed'   => min($packed, $total),
                'total'    => $total,
                'lines'    => $lines,
                'first'    => $first,
                'state'    => (string) ($r['state'] ?? 'open'),
            ];
        }
        return ['orders' => $out, 'total' => (int) $count->fetchColumn()];
    }

    // ------------------------------------------------------------------ kolejka „na telefon”

    /** @param list<int> $ids */
    public function queueAdd(int $userId, array $ids): int
    {
        $exists = $this->pdo->prepare('SELECT 1 FROM woo_orders WHERE woo_order_id = ?');
        $has = $this->pdo->prepare('SELECT 1 FROM packing_queue WHERE user_id = ? AND woo_order_id = ?');
        $ins = $this->pdo->prepare('INSERT INTO packing_queue (user_id, woo_order_id, added_at) VALUES (?, ?, ?)');
        $n = 0;
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $exists->execute([$id]);
            $has->execute([$userId, $id]);
            if ($id > 0 && $exists->fetchColumn() && !$has->fetchColumn()) {
                $ins->execute([$userId, $id, gmdate('Y-m-d H:i:s')]);
                $n++;
            }
        }
        return $n;
    }

    public function queueRemove(int $userId, int $id): void
    {
        $this->pdo->prepare('DELETE FROM packing_queue WHERE user_id = ? AND woo_order_id = ?')->execute([$userId, $id]);
    }

    /** Kolejka użytkownika: krótki opis + postęp (do listy po lewej / na telefonie). */
    public function queue(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT woo_order_id FROM packing_queue WHERE user_id = ? ORDER BY added_at, woo_order_id');
        $stmt->execute([$userId]);
        return $this->summaries(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @param list<int> $ids @return list<array> */
    public function summaries(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $o = $this->order($id);
            if ($o === null) {
                continue;
            }
            $packed = array_sum(array_column($o['items'], 'packed'));
            $total = array_sum(array_column($o['items'], 'qty'));
            $out[] = ['id' => $id, 'number' => $o['number'], 'customer' => $o['customer'], 'packed' => $packed,
                      'total' => $total, 'state' => $o['state']];
        }
        return $out;
    }

    // ------------------------------------------------------------------ telefony

    /** Jednorazowy kod do QR. Zwraca kod (jawny - trafia tylko do QR). */
    public function createPairCode(int $userId, ?int $orderId = null, ?int $now = null): string
    {
        $now ??= time();
        $this->pdo->prepare('DELETE FROM packing_pair_codes WHERE expires_at < ?')->execute([$now - 86400]);
        $code = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
        $this->pdo->prepare('INSERT INTO packing_pair_codes (code_hash, user_id, woo_order_id, expires_at) VALUES (?, ?, ?, ?)')
            ->execute([hash('sha256', $code), $userId, $orderId, $now + self::PAIR_TTL]);
        return $code;
    }

    /**
     * Wymienia kod z QR na stały token telefonu.
     * @return array{token:string,user_id:int,order_id:?int,device_id:int}
     */
    public function pair(string $code, string $deviceName, ?int $now = null): array
    {
        $c = $this->consumeCode($code, $now);
        return $this->issueDevice($c['user_id'], $deviceName, $now) + ['order_id' => $c['order_id']];
    }

    /**
     * Sprawdza i zużywa kod z QR (jednorazowy, ważny PAIR_TTL).
     * @return array{user_id:int,order_id:?int}
     */
    public function consumeCode(string $code, ?int $now = null): array
    {
        $now ??= time();
        $stmt = $this->pdo->prepare('SELECT * FROM packing_pair_codes WHERE code_hash = ?');
        $stmt->execute([hash('sha256', $code)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['used_at'] !== null || (int) $row['expires_at'] < $now) {
            throw new \RuntimeException('Kod QR jest nieważny albo już użyty — wygeneruj nowy w panelu.');
        }
        $user = $this->user((int) $row['user_id']);
        if ($user === null || !(int) $user['is_active']) {
            throw new \RuntimeException('Konto, które wygenerowało kod, jest nieaktywne.');
        }
        // Warunek used_at IS NULL chroni przed dwoma telefonami skanującymi ten sam kod naraz.
        $use = $this->pdo->prepare('UPDATE packing_pair_codes SET used_at = ? WHERE code_hash = ? AND used_at IS NULL');
        $use->execute([gmdate('Y-m-d H:i:s', $now), hash('sha256', $code)]);
        if ($use->rowCount() !== 1) {
            throw new \RuntimeException('Kod QR został już użyty.');
        }
        return ['user_id' => (int) $user['id'], 'order_id' => $row['woo_order_id'] !== null ? (int) $row['woo_order_id'] : null];
    }

    /**
     * Nowy telefon konta: zwraca jawny token (tylko do ciasteczka), w bazie zostaje skrót.
     * @return array{token:string,user_id:int,device_id:int}
     */
    public function issueDevice(int $userId, string $deviceName, ?int $now = null): array
    {
        $now ??= time();
        $user = $this->user($userId) ?? throw new \RuntimeException('Nie ma takiego konta.');
        $token = bin2hex(random_bytes(32));
        $name = mb_substr(trim($deviceName), 0, 100) ?: 'Telefon';
        $this->pdo->prepare('INSERT INTO packing_devices (user_id, name, token_hash, user_stamp, created_at, last_seen_at)
            VALUES (?, ?, ?, ?, ?, ?)')->execute([$userId, $name, hash('sha256', $token), AdminSession::stamp($user),
                gmdate('Y-m-d H:i:s', $now), gmdate('Y-m-d H:i:s', $now)]);
        return ['token' => $token, 'user_id' => $userId, 'device_id' => (int) $this->pdo->lastInsertId()];
    }

    /**
     * Telefon po tokenie z ciasteczka: urządzenie + konto, albo null (odłączony, konto zmienione, za długo nieużywany).
     * @return array{device:array,user:array}|null
     */
    public function deviceByToken(string $token, ?int $now = null): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return null;
        }
        $now ??= time();
        $stmt = $this->pdo->prepare('SELECT * FROM packing_devices WHERE token_hash = ? AND revoked_at IS NULL');
        $stmt->execute([hash('sha256', $token)]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$device) {
            return null;
        }
        $user = $this->user((int) $device['user_id']);
        $lastSeen = strtotime((string) ($device['last_seen_at'] ?? $device['created_at']) . ' UTC') ?: 0;
        if ($user === null || !(int) $user['is_active'] || !hash_equals((string) $device['user_stamp'], AdminSession::stamp($user))
            || $lastSeen < $now - self::DEVICE_DAYS * 86400) {
            return null;
        }
        if ($lastSeen < $now - 60) {
            $this->pdo->prepare('UPDATE packing_devices SET last_seen_at = ? WHERE id = ?')
                ->execute([gmdate('Y-m-d H:i:s', $now), $device['id']]);
        }
        return ['device' => $device, 'user' => $user];
    }

    /** @return list<array> sparowane telefony konta */
    public function devices(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, created_at, last_seen_at FROM packing_devices
            WHERE user_id = ? AND revoked_at IS NULL ORDER BY created_at DESC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function revokeDevice(int $userId, int $deviceId): bool
    {
        $stmt = $this->pdo->prepare('UPDATE packing_devices SET revoked_at = ? WHERE id = ? AND user_id = ? AND revoked_at IS NULL');
        $stmt->execute([gmdate('Y-m-d H:i:s'), $deviceId, $userId]);
        return $stmt->rowCount() === 1;
    }

    // ------------------------------------------------------------------ pomocnicze

    private function user(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM admin_users WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function save(int $id, array $progress, string $state, ?string $note, array $photos, string $by, ?string $packedAt, ?string $packedBy): void
    {
        $progress = array_filter($progress, static fn($v) => (int) $v > 0);
        $vals = [json_encode((object) $progress), $state, $note, json_encode(array_values($photos), JSON_UNESCAPED_UNICODE),
                 gmdate('Y-m-d H:i:s'), mb_substr($by, 0, 190), $packedAt, $packedBy !== null ? mb_substr($packedBy, 0, 190) : null];
        $upd = $this->pdo->prepare('UPDATE packing_orders SET progress = ?, state = ?, note = ?, photos = ?, updated_at = ?, updated_by = ?,
            packed_at = ?, packed_by = ? WHERE woo_order_id = ?');
        $upd->execute([...$vals, $id]);
        if ($upd->rowCount() === 0) {
            $has = $this->pdo->prepare('SELECT 1 FROM packing_orders WHERE woo_order_id = ?');
            $has->execute([$id]);
            if (!$has->fetchColumn()) {
                $this->pdo->prepare('INSERT INTO packing_orders (progress, state, note, photos, updated_at, updated_by, packed_at, packed_by, woo_order_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([...$vals, $id]);
            }
        }
    }

    /** @return array<string,array{code:string,label:string,sort:int}> sku (małe litery) => lokalizacja */
    private function locationsBySku(array $skus): array
    {
        $skus = array_values(array_unique(array_filter(array_map(static fn($s) => strtolower(trim((string) $s)), $skus))));
        if ($skus === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($skus), '?'));
        $stmt = $this->pdo->prepare("SELECT p.sku AS psku, l.* FROM products p JOIN warehouse_locations l ON l.id = p.location_id
            WHERE LOWER(p.sku) IN ({$in})");
        $stmt->execute($skus);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[strtolower((string) $r['psku'])] = ['code' => (string) $r['code'], 'label' => WarehouseLocations::label($r), 'sort' => (int) $r['sort']];
        }
        return $out;
    }

    private function ean(array $it): string
    {
        foreach (['ean', 'gtin'] as $k) {
            if (!empty($it[$k]) && is_scalar($it[$k])) {
                return (string) $it[$k];
            }
        }
        try {
            if (!empty($it['product_id'])) {
                $q = $this->pdo->prepare('SELECT ean FROM products WHERE id = ? LIMIT 1');
                $q->execute([(int) $it['product_id']]);
                if (($e = $q->fetchColumn()) !== false && $e !== null && $e !== '') {
                    return (string) $e;
                }
            }
            if (!empty($it['sku'])) {
                $q = $this->pdo->prepare('SELECT ean FROM products WHERE sku = ? LIMIT 1');
                $q->execute([(string) $it['sku']]);
                if (($e = $q->fetchColumn()) !== false && $e !== null && $e !== '') {
                    return (string) $e;
                }
            }
        } catch (\PDOException) {
        }
        return '';
    }

    /** Atrybuty pozycji (warianty Woo w meta_data, bez pól technicznych „_...”). */
    private static function attributes(array $it): string
    {
        $parts = [];
        foreach ((array) ($it['meta_data'] ?? []) as $m) {
            if (!is_array($m)) {
                continue;
            }
            $key = (string) ($m['display_key'] ?? $m['key'] ?? '');
            $val = $m['display_value'] ?? $m['value'] ?? '';
            if ($key === '' || str_starts_with($key, '_') || !is_scalar($val) || trim((string) $val) === '') {
                continue;
            }
            $parts[] = $key . ': ' . trim(strip_tags((string) $val));
        }
        return implode(' | ', $parts);
    }
}
