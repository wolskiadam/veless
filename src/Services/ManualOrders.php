<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use Pase\Queue\Queue;
use Pase\Support\Logger;

/**
 * Zamówienia dodane ręcznie w CRM (Zamówienia → „+ Nowe zamówienie”), np. sprzedaż telefoniczna,
 * na Messengerze albo na miejscu.
 *
 * Takie zamówienie istnieje tylko w CRM: nie powstaje w WooCommerce ani na Allegro (integration_id = NULL,
 * więc zmiana statusu też nigdzie nie idzie). Zapisujemy je w woo_orders w tym samym formacie co zamówienia
 * ze sklepu (billing, shipping, line_items, shipping_lines...), więc strona zamówienia, faktura / paragon
 * w wFirma, przesyłki, wydruki, wpłaty, scalanie i karta klienta działają bez zmian.
 *
 * Numery: woo_order_id z zakresu 8 000 000 001 - 8 999 999 999 (Woo ma małe numery, Allegro 9 000 000 000+),
 * numer zamówienia „R1”, „R2”... i zwykły kolejny numer CRM.
 *
 * Sklep: przy dodaniu wybieramy, z którego sklepu jest zamówienie (woo_orders.manual_shop_id = id konta sklepu
 * z integration_accounts). Tylko w CRM - nic nie idzie do sklepu; decyduje np. o koncie kurierskim / nadawcy
 * (CourierShipping::servesShop). integration_id zostaje NULL, żeby żadna synchronizacja nie dotykała zamówienia.
 *
 * Magazyn: pozycje z SKU z magazynu CRM zdejmują stan w CRM (pase_stock, a wpisany stan faktyczny razem z nim)
 * i kolejkują wysłanie stanu do sklepu - tak samo jak sprzedaż na Allegro. Pozycje spoza magazynu (sama nazwa
 * i cena) wchodzą do zamówienia i na fakturę bez ruchu na magazynie.
 */
final class ManualOrders
{
    public const ID_MIN = 8_000_000_001;
    public const ID_MAX = 8_999_999_999;

    /** Metody płatności do wyboru w formularzu (klucz trafia do payment_method jak w Woo). */
    public const PAYMENT_METHODS = [
        'bacs'      => 'Przelew na konto',
        'payu_link' => 'PayU - link do płatności',
        'cod'       => 'Za pobraniem',
        'cash'      => 'Gotówka',
        'card'      => 'Karta (terminal)',
        'other'     => 'Inna',
    ];

    public function __construct(private readonly PDO $pdo, private readonly ?Queue $queue = null) {}

    public static function migrate(PDO $pdo): void
    {
        if (!LowStock::columnExists($pdo, 'woo_orders', 'manual_shop_id')) {
            $pdo->exec('ALTER TABLE woo_orders ADD COLUMN manual_shop_id INT NULL');
        }
    }

    /**
     * Sklepy do wyboru: konta integracji, z których przychodzą zamówienia (WooCommerce, Allegro, TikTok Shop...).
     * $keepId - konto już przypisane do zamówienia; zostaje na liście, nawet gdy jest wyłączone.
     * @return array<int,string> id konta => nazwa
     */
    public static function shops(PDO $pdo, int $keepId = 0): array
    {
        $out = [];
        try {
            $sources = \Pase\Plugin\PluginRegistry::withCapability(\Pase\Plugin\Capability::ORDER_SOURCE);
            foreach ((new \Pase\Repository\IntegrationAccountRepository($pdo))->all() as $acc) {
                $type = (string) $acc['type'];
                if (!isset($sources[$type]) || (empty($acc['is_active']) && (int) $acc['id'] !== $keepId)) {
                    continue;
                }
                $typeName = $sources[$type]->name;
                $name = trim((string) ($acc['name'] ?? ''));
                $out[(int) $acc['id']] = $name !== '' && $name !== $typeName ? $name . ' (' . $typeName . ')' : $typeName;
            }
        } catch (\Throwable $e) {
            Logger::warn('Lista sklepów dla zamówienia ręcznego: ' . $e->getMessage());
        }
        return $out;
    }

    /** Zmienia sklep zamówienia ręcznego (null = bez sklepu). Zwraca true, gdy coś się zmieniło. */
    public function setShop(int $wooOrderId, ?int $shopId): bool
    {
        if (!self::isManual($wooOrderId)) {
            return false;
        }
        self::migrate($this->pdo);
        $st = $this->pdo->prepare('SELECT manual_shop_id FROM woo_orders WHERE woo_order_id = ?');
        $st->execute([$wooOrderId]);
        $cur = $st->fetchColumn();
        if ($cur === false || (int) $cur === (int) $shopId) {
            return false;
        }
        $this->pdo->prepare('UPDATE woo_orders SET manual_shop_id = ? WHERE woo_order_id = ?')->execute([$shopId, $wooOrderId]);
        (new AuditTrail($this->pdo))->record($wooOrderId, 'order.manual_shop_changed', ['manual_shop_id' => $cur !== null ? (int) $cur : null], ['manual_shop_id' => $shopId]);
        return true;
    }

    public static function isManual(int $wooOrderId): bool
    {
        return $wooOrderId >= self::ID_MIN && $wooOrderId <= self::ID_MAX;
    }

    /**
     * Sprawdza i porządkuje dane z formularza. Nic nie zapisuje.
     * @return array{order:array<string,mixed>,errors:list<string>}
     */
    public function validate(array $post): array
    {
        $errors = [];
        $str = static fn(string $k, int $max = 200): string => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($post[$k] ?? '')) ?? ''), 0, $max);
        $money = static function ($v): ?float {
            $v = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim((string) $v));
            if ($v === '') {
                return 0.0;
            }
            return preg_match('/^\d{1,7}(\.\d{1,2})?$/', $v) ? round((float) $v, 2) : null;
        };

        $address = static function (string $prefix) use ($str): array {
            return [
                'first_name' => $str($prefix . 'first_name', 80),
                'last_name'  => $str($prefix . 'last_name', 80),
                'company'    => $str($prefix . 'company', 200),
                'address_1'  => $str($prefix . 'address_1', 200),
                'address_2'  => '',
                'postcode'   => $str($prefix . 'postcode', 12),
                'city'       => $str($prefix . 'city', 100),
                'state'      => '',
                'country'    => strtoupper($str($prefix . 'country', 2)) ?: 'PL',
            ];
        };
        $shops = self::shops($this->pdo);
        $shopId = (int) ($post['shop_id'] ?? 0);
        if ($shops !== [] && !isset($shops[$shopId])) {
            $errors[] = 'Wybierz sklep, z którego jest zamówienie.';
            $shopId = 0;
        }

        $billing = $address('billing_') + ['email' => $str('billing_email', 190), 'phone' => $str('billing_phone', 40)];
        if ($billing['email'] !== '' && !filter_var($billing['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Adres e-mail „' . $billing['email'] . '” jest niepoprawny.';
        }
        if (trim($billing['first_name'] . $billing['last_name'] . $billing['company']) === '') {
            $errors[] = 'Podaj imię i nazwisko klienta albo nazwę firmy.';
        }
        $shipping = !empty($post['ship_different']) ? $address('shipping_') + ['phone' => $str('shipping_phone', 40)] : [];
        if ($shipping !== [] && trim($shipping['address_1'] . $shipping['city']) === '') {
            $errors[] = 'Uzupełnij adres dostawy albo odznacz „Inny adres dostawy”.';
        }

        // Pozycje: SKU z magazynu CRM (nazwa i cena podpowiadają się z magazynu) albo dowolna nazwa i cena.
        $items = [];
        $skus = (array) ($post['item_sku'] ?? []);
        $names = (array) ($post['item_name'] ?? []);
        $prices = (array) ($post['item_price'] ?? []);
        $qtys = (array) ($post['item_qty'] ?? []);
        $find = $this->pdo->prepare('SELECT id, sku, name FROM products WHERE sku = ? LIMIT 1');
        foreach (array_keys($names + $skus) as $i) {
            $sku = mb_substr(trim((string) ($skus[$i] ?? '')), 0, 100);
            $name = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($names[$i] ?? '')) ?? ''), 0, 250);
            if ($sku === '' && $name === '') {
                continue;   // pusty wiersz formularza
            }
            $prod = null;
            if ($sku !== '') {
                $find->execute([$sku]);
                $prod = $find->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($prod === null) {
                    $errors[] = 'Nie ma w magazynie produktu o SKU „' . $sku . '”. Wybierz go z listy albo zostaw SKU puste i wpisz samą nazwę.';
                    continue;
                }
            }
            $name = $name !== '' ? $name : (string) $prod['name'];
            $qty = (int) ($qtys[$i] ?? 1);
            $price = $money($prices[$i] ?? '');
            if ($qty < 1 || $qty > 99999) {
                $errors[] = 'Pozycja „' . $name . '”: ilość musi być od 1.';
                continue;
            }
            if ($price === null) {
                $errors[] = 'Pozycja „' . $name . '”: podaj cenę, np. 24.99.';
                continue;
            }
            $items[] = [
                'id'           => count($items) + 1,
                'product_id'   => $prod !== null ? (int) $prod['id'] : 0,
                'name'         => $name,
                'sku'          => $prod !== null ? (string) $prod['sku'] : null,
                'quantity'     => $qty,
                // Ceny brutto, jak w Allegro: bez total_tax faktura liczy brutto = total i bierze domyślną stawkę VAT.
                'price'        => $price,
                'total'        => number_format(round($price * $qty, 2), 2, '.', ''),
                'in_warehouse' => $prod !== null,
            ];
        }
        if ($items === [] && !array_filter($errors, static fn(string $e): bool => str_starts_with($e, 'Pozycja') || str_starts_with($e, 'Nie ma w magazynie'))) {
            $errors[] = 'Dodaj co najmniej jedną pozycję.';
        }

        $shipCost = $money($post['shipping_cost'] ?? '');
        if ($shipCost === null) {
            $errors[] = 'Podaj koszt dostawy, np. 14.99 (albo zostaw puste).';
            $shipCost = 0.0;
        }
        $shipMethod = $str('shipping_method', 120);

        $method = (string) ($post['payment_method'] ?? 'bacs');
        if (!isset(self::PAYMENT_METHODS[$method])) {
            $method = 'other';
        }
        $methodTitle = $method === 'other' && $str('payment_method_other', 80) !== '' ? $str('payment_method_other', 80) : self::PAYMENT_METHODS[$method];

        $invoice = null;
        if (!empty($post['wants_invoice'])) {
            $form = InvoiceData::fromForm([
                'wants'     => '1',
                'name'      => trim($billing['first_name'] . ' ' . $billing['last_name']),
                'company'   => $billing['company'],
                'address_1' => $billing['address_1'],
                'postcode'  => $billing['postcode'],
                'city'      => $billing['city'],
                'country'   => $billing['country'],
                'nip'       => $str('billing_nip', 20),
            ]);
            array_push($errors, ...$form['errors']);
            $invoice = $form['json'];
        }

        $itemsSum = array_sum(array_map(static fn(array $it): float => (float) $it['total'], $items));
        return ['errors' => $errors, 'order' => [
            'billing'   => $billing,
            'shipping'  => $shipping,
            'items'     => $items,
            'shipping_method' => $shipMethod,
            'shipping_cost' => $shipCost,
            'total'     => round($itemsSum + $shipCost, 2),
            'payment_method' => $method,
            'payment_method_title' => $methodTitle,
            'paid'      => !empty($post['paid']),
            'no_shipping' => !empty($post['no_shipping']),
            'nip'       => $invoice !== null ? (string) (json_decode($invoice, true)['nip'] ?? '') : '',
            'invoice'   => $invoice,
            'note'      => mb_substr(trim((string) ($post['customer_note'] ?? '')), 0, 2000),
            'status'    => $str('pase_status', 32) ?: 'new',
            'shop_id'   => $shopId > 0 ? $shopId : null,
        ]];
    }

    /**
     * Zapisuje zamówienie z validate()['order'], zdejmuje stan magazynowy i (opcjonalnie) zaznacza wpłatę.
     * @return int woo_order_id nowego zamówienia
     */
    public function create(array $order, string $actor): int
    {
        $now = date('Y-m-d H:i:s');
        $billing = $order['billing'];
        $customerName = trim($billing['first_name'] . ' ' . $billing['last_name']) ?: $billing['company'];
        $shippingLines = [];
        if ($order['shipping_method'] !== '' || $order['shipping_cost'] > 0) {
            $shippingLines[] = [
                'method_title' => $order['shipping_method'] !== '' ? $order['shipping_method'] : 'Dostawa',
                'total'        => number_format((float) $order['shipping_cost'], 2, '.', ''),
            ];
        }

        $id = $this->insertWithNewId(function (int $id) use ($order, $billing, $customerName, $shippingLines, $now, $actor): void {
            $seq = $id - self::ID_MIN + 1;
            $payload = [
                'id'                   => $id,
                'number'               => 'R' . $seq,
                'status'               => 'manual',
                'created_via'          => 'crm',
                'crm_created_by'       => $actor,
                'date_created'         => $now,
                'currency'             => 'PLN',
                'total'                => number_format((float) $order['total'], 2, '.', ''),
                'shipping_total'       => number_format((float) $order['shipping_cost'], 2, '.', ''),
                'billing'              => $billing + ['nip' => $order['nip']],
                'shipping'             => $order['shipping'],
                'line_items'           => $order['items'],
                'shipping_lines'       => $shippingLines,
                'payment_method'       => $order['payment_method'],
                'payment_method_title' => $order['payment_method_title'],
                'date_paid'            => null,
                'customer_note'        => $order['note'],
                'meta_data'            => $order['invoice'] !== null ? [['key' => '_billing_invoice', 'value' => '1']] : [],
            ];
            $this->pdo->prepare('INSERT INTO woo_orders (woo_order_id, integration_id, order_number, status, pase_status, currency, total,
                    customer_name, customer_email, date_created, client_token, payload, local_invoice)
                VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                $id, 'R' . $seq, 'manual', $order['status'], 'PLN', $order['total'], $customerName !== '' ? $customerName : null,
                $billing['email'] !== '' ? $billing['email'] : null, $now, bin2hex(random_bytes(24)),
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $order['invoice'],
            ]);
        });
        (new \Pase\Repository\WooOrderRepository($this->pdo))->assignNumberIfMissing($id);
        if (!empty($order['shop_id'])) {
            self::migrate($this->pdo);
            $this->pdo->prepare('UPDATE woo_orders SET manual_shop_id = ? WHERE woo_order_id = ?')->execute([(int) $order['shop_id'], $id]);
        }
        if (!empty($order['no_shipping'])) {
            // Zamówienie wirtualne - bez ikony „Do wysyłki” (OrderIndicators).
            $this->pdo->prepare('UPDATE woo_orders SET no_shipping = 1 WHERE woo_order_id = ?')->execute([$id]);
        } else {
            // Same produkty wirtualne z magazynu - zaznaczamy sami.
            (new VirtualProducts($this->pdo))->applyToOrder($id, $order['items']);
        }
        (new AuditTrail($this->pdo))->record($id, 'order.created_manually', [], ['pase_status' => $order['status']]);

        $this->takeStock($id, $order['items']);
        if ($order['paid']) {
            // Reguły „Zamówienie zostało opłacone” uruchamia order_new.php razem z „Pobrano zamówienie”, gdy zaznaczono automatyzacje.
            (new OrderPayment($this->pdo))->set($id, (float) $order['total'], $actor, false);
        }
        Logger::info("Zamówienie ręczne #{$id} dodane przez {$actor}", ['total' => $order['total']]);
        return $id;
    }

    /** Nowy numer z zakresu zamówień ręcznych; przy równoległym zapisie (ten sam numer) próbuje dalej. */
    private function insertWithNewId(callable $insert): int
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $st = $this->pdo->prepare('SELECT MAX(woo_order_id) FROM woo_orders WHERE woo_order_id BETWEEN ? AND ?');
            $st->execute([self::ID_MIN, self::ID_MAX]);
            $id = max(self::ID_MIN, (int) $st->fetchColumn() + 1) + $attempt;
            try {
                $insert($id);
                return $id;
            } catch (\PDOException $e) {
                if (!preg_match('/Duplicate|UNIQUE|PRIMARY/i', $e->getMessage())) {
                    throw $e;
                }
            }
        }
        throw new \RuntimeException('Nie udało się nadać numeru zamówienia - spróbuj jeszcze raz.');
    }

    /** Zdejmuje sprzedane sztuki ze stanu w CRM (i wpisanego stanu faktycznego) i kolejkuje wysłanie stanu do sklepu. */
    private function takeStock(int $id, array $items): void
    {
        $upd = $this->pdo->prepare('UPDATE products SET
                pase_stock = CASE WHEN COALESCE(pase_stock, 0) > ? THEN COALESCE(pase_stock, 0) - ? ELSE 0 END,
                actual_stock = CASE WHEN actual_stock IS NULL THEN NULL WHEN actual_stock > ? THEN actual_stock - ? ELSE 0 END
            WHERE id = ?');
        foreach ($items as $it) {
            if (empty($it['product_id'])) {
                continue;
            }
            foreach ([(int) $it['quantity'], (int) $it['quantity'], (int) $it['quantity'], (int) $it['quantity'], (int) $it['product_id']] as $n => $val) {
                $upd->bindValue($n + 1, $val, PDO::PARAM_INT);
            }
            $upd->execute();
            try {
                $this->queue?->enqueue('woo.stock.push', ['product_id' => (int) $it['product_id']], 'woo.stock.push:' . $it['product_id'] . ':manual:' . $id);
            } catch (\Throwable $e) {
                // Zamówienie i stan w CRM już zapisane - sklep dostanie stan przy najbliższej synchronizacji stanów.
                Logger::warn("Zamówienie ręczne #{$id}: nie zakolejkowano wysłania stanu produktu {$it['product_id']} do sklepu: " . $e->getMessage());
            }
        }
    }
}
