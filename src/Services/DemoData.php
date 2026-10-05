<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;

/**
 * Fikcyjne dane wersji demonstracyjnej (sklep ze świecami): produkty z prostymi grafikami,
 * ~140 zamówień z Woo i Allegro z ostatniego miesiąca, przesyłki, dokumenty, wiadomości
 * i przykładowe automatyzacje. Daty liczone od „teraz”, więc po nocnym resecie demo
 * zawsze wygląda świeżo. Wołane przez cli/demo_reset.php.
 */
final class DemoData
{
    public static function seed(PDO $pdo, string $imgDir): void
    {
        if (!is_dir($imgDir)) {
            mkdir($imgDir, 0775, true);
        }
        mt_srand(7);

        // Obrazki produktów: proste grafiki świec (GD), bez zdjęć z prawdziwego sklepu.
        $palette = [[214,170,120],[180,120,90],[120,150,130],[200,150,160],[150,140,190],[230,200,140],[110,130,160],[190,110,90]];
        $mkImg = static function (string $file, array $c) {
            $im = imagecreatetruecolor(600, 600);
            imagefill($im, 0, 0, imagecolorallocate($im, 246, 242, 236));
            imagefilledellipse($im, 300, 470, 300, 60, imagecolorallocate($im, 225, 218, 208));
            imagefilledrectangle($im, 180, 220, 420, 470, imagecolorallocate($im, ...$c));
            imagefilledellipse($im, 300, 220, 240, 50, imagecolorallocate($im, min(255,$c[0]+25), min(255,$c[1]+25), min(255,$c[2]+25)));
            imagefilledellipse($im, 300, 470, 240, 50, imagecolorallocate($im, max(0,$c[0]-30), max(0,$c[1]-30), max(0,$c[2]-30)));
            imagefilledrectangle($im, 180, 220, 420, 470, imagecolorallocate($im, ...$c));
            imagesetthickness($im, 4);
            imageline($im, 300, 220, 300, 180, imagecolorallocate($im, 60, 50, 40));
            imagefilledellipse($im, 300, 160, 26, 50, imagecolorallocate($im, 255, 170, 60));
            imagefilledellipse($im, 300, 168, 12, 26, imagecolorallocate($im, 255, 230, 140));
            imagefilledrectangle($im, 220, 310, 380, 380, imagecolorallocate($im, 250, 247, 240));
            imagepng($im, $file);
        };
        $products = [
            ['SW-LAW-180', 'Świeca sojowa Lawenda 180 ml', 39.90, 42, 14.20],
            ['SW-CYN-180', 'Świeca sojowa Cynamon i pomarańcza 180 ml', 39.90, 18, 14.20],
            ['SW-DRZ-300', 'Świeca Drzewo sandałowe 300 ml, drewniany knot', 64.90, 9, 23.50],
            ['SW-WAN-180', 'Świeca sojowa Wanilia 180 ml', 39.90, 3, 13.80],
            ['WS-MIX-6', 'Wosk zapachowy – zestaw 6 kostek', 24.90, 75, 7.10],
            ['WS-LEM-1', 'Wosk zapachowy Trawa cytrynowa', 9.90, 120, 2.40],
            ['KM-CER-1', 'Kominek ceramiczny do wosków', 49.00, 14, 19.00],
            ['SW-SET-3', 'Zestaw prezentowy 3 świece 100 ml', 89.00, 6, 31.00],
            ['SW-ROZ-300', 'Świeca Róża i piżmo 300 ml', 64.90, 11, 23.50],
            ['AK-GAS-1', 'Gasidło do świec, mosiądz', 29.90, 25, 9.00],
            ['SW-KAW-180', 'Świeca sojowa Kawa z karmelem 180 ml', 39.90, 0, 14.20],
            ['WS-SOS-1', 'Wosk zapachowy Sosnowy las', 9.90, 64, 2.40],
        ];
        $pdo->exec('DELETE FROM products');
        $ins = $pdo->prepare('INSERT INTO products (sku, name, pase_stock, pase_price, images, ean, weight, purchase_cost, min_stock, description, short_description, imported_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');
        foreach ($products as $i => [$sku, $name, $price, $stock, $cost]) {
            $file = strtolower($sku) . '.png';
            $mkImg($imgDir . '/' . $file, $palette[$i % count($palette)]);
            $ins->execute([$sku, $name, $stock, $price, json_encode(['/demo-img/' . $file]), '590' . str_pad((string) (1234500 + $i * 37), 10, '0', STR_PAD_LEFT),
                str_contains($sku, 'KM') ? 0.9 : 0.4, $cost, 5, '<p>Ręcznie wylewana świeca z naturalnego wosku sojowego.</p>', 'Naturalny wosk, bawełniany knot.']);
        }
        $prod = $pdo->query('SELECT id, sku, name, pase_price FROM products')->fetchAll(PDO::FETCH_ASSOC);

        // Kanały sprzedaży.
        $pdo->exec('DELETE FROM integration_accounts');
        $pdo->exec("INSERT INTO integration_accounts (id, type, name, is_active, config) VALUES
            (1, 'woocommerce', 'Sklep Demo', 1, '{\"base_url\":\"https://sklep-demo.example\"}'),
            (2, 'allegro', 'Allegro', 1, '{}'),
            (3, 'woocommerce', 'Hurtownia Demo', 1, '{\"base_url\":\"https://hurt.sklep-demo.example\"}')");

        $first = ['Anna','Piotr','Katarzyna','Marek','Joanna','Tomasz','Magdalena','Paweł','Agnieszka','Michał','Ewa','Krzysztof','Natalia','Łukasz','Zofia','Jakub','Barbara','Adrian'];
        $last  = ['Nowak','Kowalski','Wiśniewski','Wójcik','Kamiński','Lewandowski','Zieliński','Szymański','Woźniak','Dąbrowski','Kozłowski','Jankowski','Mazur','Krawczyk','Piotrowski','Grabowski'];
        $cities = [['Warszawa','00-950'],['Kraków','30-001'],['Gdańsk','80-001'],['Poznań','60-101'],['Wrocław','50-001'],['Łódź','90-001'],['Lublin','20-001'],['Toruń','87-100']];
        $streets = ['Lipowa','Słoneczna','Ogrodowa','Polna','Leśna','Kwiatowa','Szkolna','Długa'];
        $ship = [['InPost Paczkomat 24/7', 13.99], ['Kurier DPD', 16.99], ['Kurier InPost', 15.99], ['Odbiór osobisty', 0.0]];
        $statusPool = ['new','new','processing','processing','processing','shipped','shipped','shipped','shipped','cancelled'];

        $pdo->exec('DELETE FROM woo_orders'); $pdo->exec('DELETE FROM shipments');
        $repo = new \Pase\Repository\WooOrderRepository($pdo);
        for ($n = 0; $n < 140; $n++) {
            $isAllegro = $n % 3 === 1;
            $fn = $first[mt_rand(0, count($first) - 1)]; $ln = $last[mt_rand(0, count($last) - 1)];
            if (str_ends_with($fn, 'a') && str_ends_with($ln, 'ki')) { $ln = substr($ln, 0, -1) . 'a'; }
            [$city, $pc] = $cities[mt_rand(0, count($cities) - 1)];
            $street = $streets[mt_rand(0, count($streets) - 1)] . ' ' . mt_rand(1, 80);
            $mail = strtolower(strtr($fn . '.' . $ln, ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z','Ł'=>'l','Ś'=>'s','Ż'=>'z'])) . '@example.com';
            $date = date("Y-m-d H:i:s", time() - (int) ((140 - $n) * 5.1 * 3600) - mt_rand(0, 14000));
            $status = $n >= 134 ? (["new","new","processing","new","processing","new"][$n - 134]) : $statusPool[mt_rand(0, count($statusPool) - 1)];
            $items = [];
            foreach ((array) array_rand($prod, mt_rand(1, 3)) as $k) { $items[] = $prod[$k]; }
            $sum = 0.0; $lines = [];
            foreach ((array) $items as $p) {
                $q = mt_rand(1, 3); $t = round($p['pase_price'] * $q, 2); $sum += $t;
                $lines[] = ['product_id' => (int) $p['id'], 'sku' => $p['sku'], 'name' => $p['name'], 'quantity' => $q, 'price' => (float) $p['pase_price'], 'total' => number_format($t, 2, '.', '')];
            }
            [$shipName, $shipCost] = $ship[mt_rand(0, $isAllegro ? 2 : 3)];
            $total = round($sum + $shipCost, 2);
            if ($isAllegro) {
                $cf = sprintf('%08x-%04x-11ef-%04x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand());
                $login = strtolower(strtr(mb_substr($fn, 0, 3) . mb_substr($ln, 0, 4), ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z','Ł'=>'l','Ś'=>'s','Ż'=>'z'])) . mt_rand(10, 99);
                $payload = ['id' => $cf, 'buyer' => ['login' => $login, 'firstName' => $fn, 'lastName' => $ln, 'email' => "{$login}@allegromail.pl", 'phoneNumber' => '+48 600 ' . mt_rand(100, 999) . ' ' . mt_rand(100, 999),
                        'address' => ['street' => $street, 'city' => $city, 'postCode' => $pc, 'countryCode' => 'PL']],
                    'lineItems' => array_map(static fn($l) => ['id' => uniqid(), 'offer' => ['id' => (string) mt_rand(17000000000, 18999999999), 'name' => $l['name'], 'external' => ['id' => $l['sku']]], 'quantity' => $l['quantity'], 'price' => ['amount' => (string) $l['price'], 'currency' => 'PLN']], $lines),
                    'delivery' => ['method' => ['name' => 'Allegro ' . $shipName], 'cost' => ['amount' => (string) $shipCost, 'currency' => 'PLN'],
                        'address' => ['firstName' => $fn, 'lastName' => $ln, 'street' => $street, 'city' => $city, 'zipCode' => $pc, 'countryCode' => 'PL']],
                    'payment' => ['type' => 'ONLINE', 'provider' => 'P24', 'paidAmount' => ['amount' => (string) $total, 'currency' => 'PLN'], 'finishedAt' => str_replace(' ', 'T', $date) . 'Z'],
                    'summary' => ['totalToPay' => ['amount' => (string) $total, 'currency' => 'PLN']],
                    'fulfillment' => ['status' => 'NEW'], 'boughtAt' => str_replace(' ', 'T', $date) . 'Z', 'messageToSeller' => $n % 7 === 1 ? 'Proszę o staranne zapakowanie, to prezent :)' : null];
                $payload = \Pase\Support\AllegroOrderMapper::withWooFields($payload, array_map(static fn($l) => ['sku' => $l['sku'], 'product_id' => $l['product_id']], $lines));
                $id = 9_000_000_000 + crc32($cf);
                $repo->upsert($id, ['integration_id' => 2, 'order_number' => $cf, 'status' => 'NEW', 'pase_status' => $status, 'currency' => 'PLN', 'total' => $total,
                    'customer_name' => "$fn $ln", 'customer_email' => $payload['buyer']['email'], 'date_created' => $date], $payload);
            } else {
                $id = 4100 + $n; $intId = $n % 9 === 4 ? 3 : 1;
                $payload = ['id' => $id, 'number' => (string) $id, 'status' => $status === 'shipped' ? 'completed' : ($status === 'cancelled' ? 'cancelled' : 'processing'), 'currency' => 'PLN', 'total' => number_format($total, 2, '.', ''),
                    'date_created' => str_replace(' ', 'T', $date), 'date_paid' => str_replace(' ', 'T', $date), 'payment_method' => 'payu', 'payment_method_title' => ['BLIK', 'PayU', 'Przelew online'][mt_rand(0, 2)],
                    'billing' => ['first_name' => $fn, 'last_name' => $ln, 'email' => $mail, 'phone' => '+48 5' . mt_rand(10, 99) . ' ' . mt_rand(100, 999) . ' ' . mt_rand(100, 999), 'address_1' => $street, 'city' => $city, 'postcode' => $pc, 'country' => 'PL',
                        'company' => $intId === 3 ? 'Kwiaciarnia Pod Lipą s.c.' : ''],
                    'shipping' => ['first_name' => $fn, 'last_name' => $ln, 'address_1' => $street, 'city' => $city, 'postcode' => $pc, 'country' => 'PL'],
                    'line_items' => $lines, 'shipping_lines' => [['method_title' => $shipName, 'total' => number_format($shipCost, 2, '.', '')]],
                    'customer_note' => $n % 8 === 2 ? 'Czy mogę prosić o fakturę na firmę?' : ''];
                $repo->upsert($id, ['integration_id' => $intId, 'order_number' => (string) $id, 'status' => $payload['status'], 'pase_status' => $status, 'currency' => 'PLN', 'total' => $total,
                    'customer_name' => "$fn $ln", 'customer_email' => $mail, 'date_created' => $date], $payload);
            }
            $repo->assignNumberIfMissing($id);
            $pdo->prepare('UPDATE woo_orders SET imported_at = ? WHERE woo_order_id = ?')->execute([$date, $id]);
            if ($status === 'shipped') {
                $pdo->prepare("INSERT INTO shipments (woo_order_id, integration_id, courier_code, waybill_no, price, status, created_at, tracking_status, tracking_carrier)
                    VALUES (?, ?, ?, ?, ?, 'created', ?, ?, ?)")->execute([$id, 1, str_contains($shipName, 'DPD') ? 'dpd' : 'inpost',
                    (string) mt_rand(600000000, 699999999) . mt_rand(100000000, 999999999), $shipCost, $date, ['delivered', 'in_transit', 'delivered'][mt_rand(0, 2)], str_contains($shipName, 'DPD') ? 'DPD' : 'InPost']);
            }
        }
        // Księgowość, kurier i dokumenty / wiadomości do kilku zamówień.
        $pdo->exec("INSERT INTO integration_accounts (id, type, name, is_active, config) VALUES
            (4, 'wfirma', 'wFirma', 1, '{\"company_id\":\"1\"}'), (5, 'blpaczka', 'BLPaczka', 1, '{}')");
        $pdo->exec('DELETE FROM order_documents'); $pdo->exec('DELETE FROM order_messages');
        $docs = $pdo->prepare("INSERT INTO order_documents (woo_order_id, integration_id, provider, document_type, remote_id, status, message, created_at, updated_at) VALUES (?, 4, 'wfirma', ?, ?, 'issued', ?, NOW(), NOW())");
        foreach ($pdo->query("SELECT woo_order_id, pase_status FROM woo_orders WHERE pase_status IN ('shipped','processing')")->fetchAll(PDO::FETCH_ASSOC) as $i => $o) {
            $type = $i % 4 === 0 ? 'normal' : 'receipt';
            $docs->execute([$o['woo_order_id'], $type, (string) (8800 + $i), $type === 'normal' ? 'FV ' . (101 + $i) . '/10/2026' : 'PAR ' . (301 + $i) . '/2026']);
        }
        $o = $pdo->query("SELECT woo_order_id FROM woo_orders WHERE woo_order_id = 4100")->fetchColumn();
        $m = $pdo->prepare('INSERT INTO order_messages (woo_order_id, sender, author_name, body, is_read, created_at) VALUES (?,?,?,?,1,?)');
        $m->execute([$o, 'client', 'Michał', 'Dzień dobry, czy paczka wyjdzie jeszcze dzisiaj? Chciałbym dać ją w prezencie w sobotę.', date('Y-m-d H:i:s', time() - 7200)]);
        $m->execute([$o, 'staff', 'Administrator', "Dzień dobry!\n\nTak, paczka wyszła dziś przed 15:00, numer śledzenia jest w zamówieniu. Powinna być u Pana jutro.\n\nPozdrawiamy", date('Y-m-d H:i:s', time() - 6000)]);

        // Automatyzacje.
        $pdo->exec('DELETE FROM automation_rules');
        $r = new \Pase\Repository\AutomationRuleRepository($pdo);
        $r->save(null, 'Opłacone zamówienie → paragon i realizacja', 'order.paid', true, 10,
            [['field' => 'is_virtual', 'op' => 'eq', 'value' => '0']],
            [['type' => 'issue_sales_document', 'params' => ['receipt_type' => 'receipt']], ['type' => 'set_status', 'params' => ['status' => 'processing']], ['type' => 'send_email', 'params' => ['template' => 'basic_processing']]]);
        $r->save(null, 'Firma chce fakturę → faktura VAT', 'order.imported', true, 20,
            [['field' => 'wants_invoice', 'op' => 'eq', 'value' => '1'], ['field' => 'payment_status', 'op' => 'eq', 'value' => 'paid']],
            [['type' => 'issue_invoice', 'params' => ['doc_type' => 'normal']]]);
        $r->save(null, 'Nadana paczka → e-mail z numerem przesyłki', 'shipment.created', true, 30, [],
            [['type' => 'set_status', 'params' => ['status' => 'shipped']], ['type' => 'send_email', 'params' => ['template' => 'basic_ready']]]);
        $r->save(null, 'Doręczona → prośba o opinię', 'shipment.status', true, 40,
            [['field' => 'shipment_status', 'op' => 'eq', 'value' => 'delivered']],
            [['type' => 'send_email', 'params' => ['template' => 'basic_review']]]);
        $r->save(null, 'Duże zamówienie z hurtowni → SMS do klienta', 'order.imported', false, 50,
            [['field' => 'total', 'op' => 'gt', 'value' => '500'], ['field' => 'source', 'op' => 'eq', 'value' => 'integration:3']],
            [['type' => 'send_sms', 'params' => ['template' => 'Dziękujemy za zamówienie {{order_number}}!']]]);
    }
}
