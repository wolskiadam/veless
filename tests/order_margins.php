<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Marża: koszt zakupu produktów, migawki kosztu na pozycjach zamówień, opłaty Allegro i raport do statystyk.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Services\OrderMargins as M;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }
function near(float $a, float $b): bool { return abs($a - $b) < 0.006; }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT UNIQUE, name TEXT, pase_price REAL, woo_product_id INTEGER, woo_variation_id INTEGER)');
$pdo->exec('CREATE TABLE woo_orders (woo_order_id INTEGER PRIMARY KEY, integration_id INTEGER, pase_status TEXT, currency TEXT, total REAL,
    date_created TEXT, lifecycle TEXT DEFAULT \'active\', payload TEXT, local_items TEXT NULL, local_edited_at TEXT NULL)');
$pdo->exec('CREATE TABLE integration_accounts (id INTEGER PRIMARY KEY, type TEXT, name TEXT, is_active INTEGER DEFAULT 1, config TEXT)');
$pdo->exec("INSERT INTO integration_accounts (id, type, name) VALUES (1, 'woocommerce', 'Mój Sklep'), (3, 'allegro', 'Allegro')");
M::migrate($pdo);
M::migrate($pdo);
$cols = array_column($pdo->query('PRAGMA table_info(products)')->fetchAll(), 'name');
check(in_array('purchase_cost', $cols, true) && in_array('purchase_cost_at', $cols, true), 'migration adds purchase cost to products (twice-safe)');

$pdo->exec("INSERT INTO products (id, sku, name, pase_price, woo_product_id, woo_variation_id) VALUES
    (1, 'SW-1', 'Świeca lawenda', 49.20, 100, NULL),
    (2, 'SW-2', 'Świeca wanilia', 36.90, 200, 201),
    (3, 'WOSK', 'Wosk sojowy 1 kg', 61.50, 300, NULL),
    (4, 'KNOT', 'Knot drewniany', 12.30, 400, NULL)");

// --- Kwoty kosztu ---
check(M::parseCost('12,50') === 12.5 && M::parseCost(' 1 234,00 zł') === 1234.0 && M::parseCost('1.234,5') === 1234.5 && M::parseCost('7') === 7.0, 'cost parsing: comma, spaces, currency, thousands');
check(M::parseCost('') === null && M::parseCost('  ') === null, 'empty cost = not set');
check(M::parseCost('abc') === false && M::parseCost('-3') === false, 'invalid or negative cost rejected');

$svc = new M($pdo, 23);
check($svc->setCosts([1 => 10.0, 2 => 8.0]) === 2 && $svc->setCosts([1 => 10.0]) === 0, 'setCosts counts only real changes');
check($svc->coverage() === ['total' => 4, 'with_cost' => 2], 'coverage: products with cost');

// --- Import CSV ---
$r = $svc->importCsv("\xEF\xBB\xBFSKU;Nazwa;Koszt zakupu netto\nWOSK;Wosk;20,00\nSW-1;Świeca;\nBRAK;Nie ma;5\nKNOT;Knot;dużo\n");
check($r['updated'] === 1 && $r['unknown'] === ['BRAK'] && count($r['invalid']) === 1, 'CSV import: header, unknown SKU and bad amount reported');
check((float) $pdo->query('SELECT purchase_cost FROM products WHERE id = 1')->fetchColumn() === 10.0, 'CSV import: empty cost does not clear an existing cost');
check($svc->importCsv("KNOT,1.5")['updated'] === 1, 'CSV import without header, comma separated');
$svc->setCosts([4 => null]);
check($pdo->query('SELECT purchase_cost FROM products WHERE id = 4')->fetchColumn() === null, 'cost can be cleared');
$csv = $svc->exportCsv(true);
check(str_contains($csv, "KNOT;Knot drewniany;\n") && !str_contains($csv, 'SW-1'), 'export of products without cost');

// --- Zamówienia ---
$order = static function (int $id, int $integration, string $date, array $payload, string $status = 'shipped', string $currency = 'PLN') use ($pdo): void {
    $pdo->prepare('INSERT INTO woo_orders (woo_order_id, integration_id, pase_status, currency, date_created, payload) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$id, $integration, $status, $currency, $date, json_encode($payload)]);
};
// Sklep z podatkami: total = netto. SW-1 x2 (koszt 10), wariant SW-2 bez SKU w pozycji (koszt 8), pozycja spoza magazynu.
$order(1, 1, '2026-09-10 10:00:00', ['line_items' => [
    ['product_id' => 100, 'sku' => 'SW-1', 'name' => 'Świeca lawenda', 'quantity' => 2, 'total' => '80.00', 'total_tax' => '18.40'],
    ['product_id' => 200, 'variation_id' => 201, 'sku' => '', 'name' => 'Świeca wanilia', 'quantity' => 1, 'total' => '30.00', 'total_tax' => '6.90'],
    ['product_id' => 999, 'sku' => 'GRATIS', 'name' => 'Karta', 'quantity' => 1, 'total' => '5.00', 'total_tax' => '1.15'],
]]);
// Allegro: ceny brutto, product_id = id w CRM (AllegroOrderMapper). WOSK koszt 20; KNOT bez kosztu.
$order(9000000001, 3, '2026-09-11 10:00:00', ['id' => 'cf-1', 'lineItems' => [['id' => 'li1']], 'line_items' => [
    ['product_id' => 3, 'sku' => 'WOSK', 'name' => 'Wosk', 'quantity' => 1, 'total' => '61.50'],
    ['product_id' => 4, 'sku' => 'KNOT', 'name' => 'Knot', 'quantity' => 2, 'total' => '24.60'],
]]);
// Allegro zapisane przed mapperem (tylko lineItems) - mapujemy w locie, SKU z sygnatury oferty.
$order(9000000002, 3, '2026-09-12 10:00:00', ['id' => 'cf-2', 'lineItems' => [
    ['id' => 'x', 'quantity' => 1, 'price' => ['amount' => '49.20'], 'offer' => ['id' => '777', 'name' => 'Świeca lawenda', 'external' => ['id' => 'SW-1']]],
]]);
$order(4, 1, '2026-09-13 10:00:00', ['line_items' => [['sku' => 'SW-1', 'quantity' => 5, 'total' => '200.00', 'total_tax' => '46.00']]], 'cancelled');
$order(5, 1, '2026-09-13 11:00:00', ['line_items' => [['sku' => 'SW-1', 'quantity' => 1, 'total' => '10.00', 'total_tax' => '2.30']]], 'shipped', 'EUR');
$order(6, 1, '2026-09-14 11:00:00', ['line_items' => [['sku' => 'SW-1', 'quantity' => 1, 'total' => '40.00', 'total_tax' => '9.20']]]);
$pdo->exec("UPDATE woo_orders SET lifecycle = 'trashed' WHERE woo_order_id = 6");

check($svc->pendingCount() === 5, 'trashed orders are not snapshotted');
check($svc->syncPending() === 5 && $svc->syncPending() === 0, 'snapshot once per order');
$line = static fn(int $o, int $n) => $pdo->query("SELECT * FROM margin_lines WHERE woo_order_id = $o AND line_no = $n")->fetch();
check((int) $line(1, 0)['product_id'] === 1 && (float) $line(1, 0)['unit_cost'] === 10.0 && (float) $line(1, 0)['revenue_net'] === 80.0, 'Woo line matched by SKU, net from total when taxed');
check((int) $line(1, 1)['product_id'] === 2 && (float) $line(1, 1)['unit_cost'] === 8.0, 'Woo variation without SKU matched by variation id');
check($line(1, 2)['product_id'] === null && $line(1, 2)['unit_cost'] === null, 'line outside the warehouse has no cost (not zero)');
check((int) $line(9000000001, 0)['product_id'] === 3 && near((float) $line(9000000001, 0)['revenue_net'], 50.0), 'Allegro gross price divided by VAT');
check((int) $line(9000000001, 1)['product_id'] === 4 && $line(9000000001, 1)['unit_cost'] === null, 'product without cost stays without cost');
check((int) $line(9000000002, 0)['product_id'] === 1 && near((float) $line(9000000002, 0)['revenue_net'], 40.0), 'old Allegro payload mapped on the fly');
check($pdo->query("SELECT allegro_order_id FROM margin_orders WHERE woo_order_id = 9000000001")->fetchColumn() === 'cf-1', 'Allegro checkout id kept for fees');

// Zmiana kosztu nie przepisuje historii; brakujący koszt uzupełnia się później.
$svc->setCosts([1 => 99.0, 4 => 3.0]);
check((float) $line(1, 0)['unit_cost'] === 10.0 && $line(1, 0)['cost_source'] === 'order', 'later cost change does not rewrite snapshots');
check((float) $line(9000000001, 1)['unit_cost'] === 3.0 && $line(9000000001, 1)['cost_source'] === 'later', 'missing cost filled when entered later');
$svc->setCosts([1 => 10.0]);

// --- Opłaty Allegro ---
$fees = [
    ['id' => 'e1', 'order' => ['id' => 'cf-1'], 'type' => ['id' => 'SUC', 'name' => 'Prowizja od sprzedaży'], 'value' => ['amount' => '-12.30', 'currency' => 'PLN'], 'occurredAt' => '2026-09-11T11:00:00Z'],
    ['id' => 'e2', 'order' => ['id' => 'cf-1'], 'type' => ['id' => 'HB4', 'name' => 'Opłata za dostawę InPost'], 'value' => ['amount' => '-9.99', 'currency' => 'PLN']],
    ['id' => 'e3', 'order' => ['id' => 'cf-2'], 'type' => ['id' => 'SUC', 'name' => 'Prowizja od sprzedaży'], 'value' => ['amount' => '-6.15', 'currency' => 'PLN']],
    ['id' => 'e4', 'type' => ['id' => 'LIS', 'name' => 'Opłata za wystawienie'], 'value' => ['amount' => '-1.00', 'currency' => 'PLN']],
    ['id' => 'e5', 'order' => ['id' => 'cf-1'], 'type' => ['id' => 'PAD', 'name' => 'Pobranie opłat z wpływów'], 'value' => ['amount' => '12.30', 'currency' => 'PLN']],
];
check($svc->saveAllegroFees($fees) === 3 && $svc->saveAllegroFees($fees) === 3, 'fees with an order saved idempotently, settlements skipped');
check((int) $pdo->query('SELECT COUNT(*) FROM allegro_order_fees')->fetchColumn() === 3, 'no duplicate fee rows');

// --- Raport ---
$rep = $svc->report('2026-09-01', '2026-09-30');
$t = $rep['total'];
// Przychód: 80 + 30 + 5 + 50 + 20 (knot 24.60/1.23) + 40 = 225. Z kosztem: 80+30+50+20+40 = 220.
// Koszt: 2*10 + 8 + 20 + 2*3 + 10 = 64. Marża 156.
check($t['orders'] === 3 && near($t['revenue'], 225.0) && near($t['revenue_costed'], 220.0), 'report: cancelled, EUR and trashed orders excluded; revenue net');
check(near($t['cost'], 64.0) && near($t['margin'], 156.0) && $t['margin_pct'] === 70.9, 'report: cost of goods and margin');
// Opłaty netto: cf-1 12.30/1.23 = 10 (dostawa pominięta), cf-2 6.15/1.23 = 5.
check(near($t['fees'], 15.0) && near($t['fees_costed'], 15.0) && near($t['margin_after_fees'], 141.0), 'report: Allegro commission subtracted net, delivery fees skipped');
check(near($t['coverage_pct'], 97.8), 'report: share of sales with known cost');
$ch = $rep['channels'];
check(near($ch['int:1']['margin'], 82.0) && near($ch['int:3']['fees'], 15.0) && near($ch['int:3']['margin_after_fees'], 59.0), 'report per channel');
$byName = array_column($rep['products'], null, 'sku');
check($byName['GRATIS']['missing'] === 1 && $byName['GRATIS']['margin_pct'] === null, 'report: product without cost flagged, no margin %');
check($byName['SW-1']['qty'] === 3 && near($byName['SW-1']['margin'], 90.0), 'report per product across channels');
check(array_keys($rep['periods']) === ['2026-09-10', '2026-09-11', '2026-09-12'] && near($rep['periods']['2026-09-11']['fees'], 10.0), 'report per day with fees');
check(array_keys($svc->report('2026-01-01', '2026-09-30')['periods']) === ['2026-09'], 'long range grouped by month');
check($svc->report('2026-09-01', '2026-09-30', 'allegro')['total']['orders'] === 2, 'report source filter');

// Opłata dzielona proporcjonalnie, gdy część pozycji nie ma kosztu.
$svc->setCosts([4 => null]);
$pdo->exec("UPDATE margin_lines SET unit_cost = NULL, cost_source = NULL WHERE product_id = 4");
$t = $svc->report('2026-09-01', '2026-09-30')['total'];
check(near($t['fees'], 15.0) && near($t['fees_costed'], 10 * 50 / 70 + 5), 'fee prorated to lines with cost');

// Produkty sprzedane bez kosztu - od największej sprzedaży.
$missing = $svc->soldWithoutCost('2026-09-01 00:00:00');
check(count($missing) === 2 && $missing[0]['sku'] === 'KNOT' && $missing[1]['sku'] === 'GRATIS', 'sold without cost, biggest first');

// Lokalna edycja pozycji: produkty z migawki zachowują koszt, nowe dostają bieżący.
$svc->setCosts([2 => 8.5]);
$pdo->prepare('UPDATE woo_orders SET local_items = ?, local_edited_at = ? WHERE woo_order_id = 1')->execute([json_encode([
    ['product_id' => 100, 'sku' => 'SW-1', 'name' => 'Świeca lawenda', 'price' => 40, 'quantity' => 1, 'total' => 40],
    ['product_id' => 3, 'sku' => 'WOSK', 'name' => 'Wosk', 'price' => 50, 'quantity' => 1, 'total' => 50],
]), '2026-09-15 10:00:00']);
$svc->setCosts([1 => 11.0]);
check($svc->syncPending() === 1 && $svc->syncPending() === 0, 'local item edit refreshes the snapshot once');
check((float) $line(1, 0)['unit_cost'] === 10.0 && (float) $line(1, 1)['unit_cost'] === 20.0 && $line(1, 2) === false, 'kept cost for existing product, current cost for added one');
check(near((float) $line(1, 1)['revenue_net'], 50.0), 'local items in a taxed order stay net');

// Przelicz od nowa: bieżące koszty.
check($svc->rebuildAll() === 5 && (float) $line(1, 0)['unit_cost'] === 11.0, 'rebuild uses current costs');

// Brak wFirma = 23%; wFirma „zw” = bez VAT.
check((new M($pdo))->defaultVat() === 23, 'default VAT 23% without wFirma');
$pdo->exec("INSERT INTO integration_accounts (id, type, name, config) VALUES (5, 'wfirma', 'wFirma', '{\"default_vat\":\"zw\"}')");
check((new M($pdo))->defaultVat() === 0, 'VAT-exempt wFirma: gross = net');

// Firma bez VAT (Konfiguracja → Firma): bez dzielenia przez VAT, koszty z migawek zostają.
$pdo->exec('DELETE FROM integration_accounts WHERE id = 5');
$pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
check(\Pase\Services\CompanySettings::isVatPayer($pdo) && \Pase\Services\CompanySettings::netSuffix($pdo) === ' netto', 'VAT payer by default');
$pdo->exec('UPDATE products SET purchase_cost = 99 WHERE id = 3');
\Pase\Services\CompanySettings::setVatPayer($pdo, false);
$nv = new M($pdo);
check(!$nv->vatPayer() && $nv->defaultVat() === 0 && \Pase\Services\CompanySettings::netSuffix($pdo) === '', 'non-VAT company: no VAT rate, no "netto" label');
check($nv->pendingCount() === 5 && $nv->syncPending() === 5 && $nv->syncPending() === 0, 'switching VAT status re-snapshots every order once');
check(near((float) $line(9000000001, 0)['revenue_net'], 61.5) && near((float) $line(9000000002, 0)['revenue_net'], 49.2), 'non-VAT company: Allegro revenue is the price the customer paid');
check((float) $line(9000000001, 0)['unit_cost'] === 20.0, 'non-VAT company: cost from the order time is kept');
check(near((float) $line(1, 0)['revenue_net'], 40.0), 'non-VAT company: locally edited line taken as entered');
$t = $nv->report('2026-09-01', '2026-09-30')['total'];
check(near($t['fees'], 18.45) && $nv->report('2026-09-01', '2026-09-30')['vat_payer'] === false, 'non-VAT company: Allegro fees not divided by VAT');
\Pase\Services\CompanySettings::setVatPayer($pdo, false);
check((new M($pdo))->pendingCount() === 0, 'saving the same VAT status does not re-snapshot');
\Pase\Services\CompanySettings::setVatPayer($pdo, true);
$pv = new M($pdo);
check($pv->syncPending() === 5 && near((float) $line(9000000001, 0)['revenue_net'], 50.0) && near($pv->report('2026-09-01', '2026-09-30')['total']['fees'], 15.0), 'back to VAT payer: net amounts again');

echo "PASS: $checks order margin checks\n";
