<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Wypych stanu CRM -> oferty Allegro (zadanie woo.stock.push) - SQLite, klient Allegro podstawiony, bez sieci.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Handlers\AllegroStockPushHandler;
use Pase\Handlers\StockPushHandler;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

final class FakeAllegro
{
    public array $patched = [];
    public array $queries = [];
    public ?string $token = 'tok';
    public array $fail = [];
    public array $offers = [];
    public function bearerToken(): ?string { return $this->token; }
    public function listOffersRaw(string $q): array { $this->queries[] = $q; return $this->offers; }
    public function updateOfferQuantity(string $id, int $qty): bool
    {
        if (in_array($id, $this->fail, true)) { return false; }
        $this->patched[$id] = $qty;
        return true;
    }
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT, pase_stock INT NULL)');
$pdo->exec('CREATE TABLE product_mappings (id INTEGER PRIMARY KEY, sku TEXT, allegro_offer_id TEXT NULL)');
$pdo->exec('CREATE TABLE offer_templates (id INTEGER PRIMARY KEY, product_id INT, marketplace TEXT, data TEXT)');
$pdo->exec("INSERT INTO products VALUES (1,'WMG60GC',10),(2,'BEZ-OFERT',4),(3,'BEZ-STANU',NULL)");
$pdo->exec("INSERT INTO product_mappings (sku, allegro_offer_id) VALUES ('WMG60GC','111'),('INNY','999')");
$pdo->exec("INSERT INTO offer_templates (product_id, marketplace, data) VALUES (1,'allegro','{\"offer_id\":\"222\"}'),(1,'erli','{\"offer_id\":\"333\"}')");

$api = new FakeAllegro();
$api->offers = [
    ['id' => '444', 'external' => ['id' => 'WMG60GC'], 'publication' => ['status' => 'ACTIVE']],
    ['id' => '555', 'external' => ['id' => 'WMG60GC'], 'publication' => ['status' => 'ENDED']],
    ['id' => '666', 'external' => ['id' => 'WMG60GC-X'], 'publication' => ['status' => 'ACTIVE']],
    ['id' => '111', 'external' => ['id' => 'WMG60GC'], 'publication' => ['status' => 'ACTIVE']],
];
$h = new AllegroStockPushHandler($pdo, fn(): object => $api);

$res = $h->handle(['product_id' => 1]);
ksort($api->patched);
check($res === 'Allegro: stan 10 -> oferty 111, 222, 444', 'result for the queue history names the offers and stock');
check($api->patched === ['111' => 10, '222' => 10, '444' => 10], 'stock 10 goes to linked, CRM-listed and active same-signature offers, each once');
check($api->queries === ['external.id=WMG60GC&limit=20'], 'signature lookup by SKU');

$api->patched = [];
$h->handle(['product_id' => 1, 'what' => 'price']);
$h->handle(['product_id' => 1, 'allegro' => false]);
$h->handle(['product_id' => 3]);
check($api->patched === [], 'price-only, scheduled (allegro=false) and product without stock push nothing');

$api->offers = [];
check($h->handle(['product_id' => 2]) === 'Allegro: brak powiązanych ofert' && $api->patched === [], 'product without any Allegro offer: nothing to push, no error, said in history');

$api->token = null;
$h->handle(['product_id' => 1]);
check($api->patched === [], 'Allegro not connected: skipped quietly');
$api->token = 'tok';

$api->fail = ['222'];
$err = null;
try { $h->handle(['product_id' => 1]); } catch (RuntimeException $e) { $err = $e->getMessage(); }
check($err !== null && str_contains($err, '222') && str_contains($err, 'ustawiono w 111') && ($api->patched['111'] ?? null) === 10, 'failed offer -> exception for retry, other offers still updated and named');
$api->fail = [];

// Złożony handler: błąd sklepu nie blokuje Allegro, a wyjątek i tak leci dalej (ponowienie).
$api->patched = [];
$shop = new class { public function handle(array $p): void { throw new RuntimeException('Woo down'); } };
$err = null;
try { (new StockPushHandler([$shop, $h]))->handle(['product_id' => 1]); } catch (RuntimeException $e) { $err = $e->getMessage(); }
check($err === 'Woo down · Allegro: stan 10 -> oferty 111, 222' && ($api->patched['111'] ?? null) === 10, 'shop failure does not stop the Allegro push and is rethrown with what did go through');
$okShop = new class { public function handle(array $p): string { return 'Sklep AWIEN WWW: stan 10'; } };
check((new StockPushHandler([$okShop, $h]))->handle(['product_id' => 1]) === 'Sklep AWIEN WWW: stan 10 · Allegro: stan 10 -> oferty 111, 222', 'combined result: shop and Allegro');
$void = new class { public function handle(array $p): void {} };
check((new StockPushHandler([$void]))->handle(['product_id' => 1]) === null, 'nothing done -> no result');

// Klient Allegro, którego nie da się utworzyć, nie wywraca wypychu do sklepu.
$broken = new AllegroStockPushHandler($pdo, function (): object { throw new RuntimeException('no config'); });
$broken->handle(['product_id' => 1]);
check(true, 'missing Allegro client is skipped');

echo "\n{$checks} checks passed\n";
