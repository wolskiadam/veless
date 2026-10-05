<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// GS1 (MojeGS1): pobranie kart, dopasowanie po EAN, kolejny wolny GTIN, nadanie numeru tylko po sprawdzeniu - SQLite, atrapa API.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
    if (str_starts_with($class, 'PasePlugin\\')) {
        [$vendor, $rest] = explode('\\', substr($class, 11), 2);
        require dirname(__DIR__) . '/integrations/' . strtolower($vendor) . '/' . str_replace('\\', '/', $rest) . '.php';
    }
});
use Pase\Repository\IntegrationAccountRepository;
use Pase\Services\Gs1;
use PasePlugin\Gs1\Gs1Client;
use PasePlugin\Gs1\Gs1Plugin;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE settings (setting_key VARCHAR(64) PRIMARY KEY, setting_value TEXT NULL)');
$pdo->exec('CREATE TABLE integration_accounts (id INTEGER PRIMARY KEY, type VARCHAR(48) NOT NULL, name VARCHAR(120) NOT NULL, is_active INT NOT NULL DEFAULT 1,
    config TEXT NULL, webhook_secret VARCHAR(255) NULL, access_token TEXT NULL, refresh_token TEXT NULL, token_expires_at DATETIME NULL)');
$pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT, name TEXT, ean TEXT NULL, description TEXT NULL, short_description TEXT NULL,
    payload TEXT NULL, woo_product_id INT NULL, woo_variation_id INT NULL, source_integration_id INT NULL)');
$pdo->exec("INSERT INTO products (id, sku, name, ean, short_description, payload) VALUES
    (1,'GB-1L','Gingerbread 1l','5901234500012',NULL,NULL),
    (2,'MW-1L','Mulled Wine 1l',' 05901234500029 ',NULL,NULL),
    (3,'CC-1L','Clementine 1l',NULL,'<p>Wosk zapachowy o zapachu klementynki, ręcznie robiony.</p>','{\"permalink\":\"https://sklep.test/cc\"}'),
    (4,'LAV-500','Lavender 500 ml','4006381333931',NULL,NULL),
    (5,'VAN-1L','Vanilla 1l',NULL,NULL,NULL)");
Gs1::migrate($pdo);
Gs1::migrate($pdo); // drugi raz bez błędu

check(Gs1::checkDigit('590123450001') === 2 && Gs1::validGtin('5901234500012') && !Gs1::validGtin('5901234500013'), 'GTIN check digit');
check(Gs1::normalize(' 05901234500029 ') === '5901234500029' && Gs1::normalize('00012345678905') === '0012345678905', 'GTIN-14 with leading zero equals GTIN-13');

// Atrapa MojeGS1: 3 karty na 2 stronach (limit strony w teście ignorowany - API zwraca po 2).
$calls = [];
$cards = [
    ['type' => 'products', 'id' => '5901234500012', 'attributes' => ['name' => 'Lumio Gingerbread 1 l', 'brandName' => 'Lumio', 'status' => 'ACT', 'gpcCode' => 10000511, 'netContentUnit' => 'l', 'lastModificationDate' => '2026-01-01T00:00:00Z']],
    ['type' => 'products', 'id' => '5901234500029', 'attributes' => ['name' => 'Lumio Mulled Wine 1 l', 'brandName' => 'Lumio', 'status' => 'ACT', 'gpcCode' => 10000511, 'lastModificationDate' => '2026-02-01T00:00:00Z',
        'qualityDetails' => ['suggestions' => ['Dodaj zdjęcie produktu']]]],
    ['type' => 'products', 'id' => '5901234500036', 'attributes' => ['name' => 'Lumio Vanilla 1 l', 'brandName' => 'Lumio', 'status' => 'ACT', 'internalSymbol' => 'VAN-1L', 'gpcCode' => 99999999, 'netContentUnit' => 'l', 'lastModificationDate' => '2026-03-01T00:00:00Z']],
];
$remote = [];   // karty "utworzone" przez PUT
$transport = static function (string $method, string $url, array $headers, ?string $body) use (&$calls, &$cards, &$remote): array {
    $calls[] = [$method, $url, $headers, $body];
    if (($headers['Authorization'] ?? '') !== 'Basic ' . base64_encode('api-user:secret')) {
        return [401, '{"status":401,"title":"Błąd autoryzacji"}'];
    }
    $path = (string) parse_url($url, PHP_URL_PATH);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    if ($method === 'GET' && $path === '/api/v2/products') {
        $page = (int) ($q['page']['offset'] ?? 1);
        $chunk = array_slice($cards, ($page - 1) * 2, 2);
        $base = 'https://mojegs1.pl/api/v2/products?page[limit]=2&page[offset]=';
        return [200, json_encode(['links' => ['self' => $base . $page, 'next' => $base . min($page + 1, 2), 'last' => $base . '2'], 'data' => $chunk])];
    }
    if (preg_match('~^/api/v2/products/(\d+)$~', $path, $m)) {
        $all = array_column(array_merge($cards, $remote), null, 'id');
        if ($method === 'GET') {
            return isset($all[$m[1]]) ? [200, json_encode(['data' => $all[$m[1]]])] : [404, '{"status":404,"title":"Nie znaleziono zasobu"}'];
        }
        if ($method === 'PUT') {
            $b = json_decode((string) $body, true);
            $remote[] = $b['data'];
            return [200, '{"result":"OK","qualityDetails":{"suggestions":["Dodaj zdjęcie"]}}'];
        }
    }
    return [500, ''];
};
// Rdzeń dostaje klienta z wtyczki (tu z atrapą HTTP); dane konta z integration_accounts.
$factory = static fn(array $config): Gs1Client => new Gs1Client($config, $transport);
$accounts = new IntegrationAccountRepository($pdo);
$setCfg = static function (array $patch) use ($pdo, $accounts, $factory): Gs1 {
    $acc = $accounts->firstActive('gs1');
    $accounts->updateConfig((int) $acc['id'], array_merge($acc['config'], $patch));
    return new Gs1($pdo, $factory);
};
$gs1 = new Gs1($pdo, $factory);

check(!$gs1->configured() && $gs1->syncDue(), 'not configured without a GS1 plugin account');
try { $gs1->sync(); check(false, 'sync without credentials'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'Brak danych dostępu') && str_contains($e->getMessage(), 'Integracje'), 'sync without credentials points to the plugin settings'); }
try { (new Gs1($pdo, static fn(array $c) => null))->sync(); check(false, 'sync without plugin'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'Wtyczka GS1'), 'plugin disabled: GS1 calls stop with a clear message'); }

// Dane z dawnych ustawień GS1 (przed wtyczką) przechodzą same do konta wtyczki.
$pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('GS1_API_LOGIN','api-user'), ('GS1_API_PASSWORD','wrong'), ('GS1_COMPANY_PREFIX',''), ('GS1_PUSH_EAN_WOO','1')");
Gs1::migrate($pdo);
Gs1::migrate($pdo);
$acc = $accounts->activeByType('gs1');
check(count($acc) === 1 && $acc[0]['config'] === ['login' => 'api-user', 'password' => 'wrong', 'prefix' => '', 'push_woo' => '1']
    && (int) $pdo->query("SELECT COUNT(*) FROM settings WHERE setting_key LIKE 'GS1_API%'")->fetchColumn() === 0, 'old GS1 settings moved once into the plugin account and removed');
$gs1 = new Gs1($pdo, $factory);
check($gs1->configured() && $gs1->pushToWoo(), 'core reads credentials and options from the plugin account');
$t = (new Gs1Client($acc[0]['config'], $transport))->test();
check(!$t['ok'] && str_contains($t['message'], '401') && str_contains($t['message'], 'Zmień dane api'), 'wrong password: 401 message points to MojeGS1');
$gs1 = $setCfg(['password' => 'secret']);
check((new Gs1Client($accounts->firstActive('gs1')['config'], $transport))->test()['ok'], 'connection test passes with the right password');
$plugin = (new Gs1Plugin())->withConfig(['login' => 'api-user', 'password' => 'secret', 'prefix' => '59']);
check(!$plugin->testConnection()['ok'] && str_contains($plugin->testConnection()['message'], 'Prefiks'), 'too short company prefix rejected by the plugin test');
$m = (new Gs1Plugin())->manifest();
check($m->type === 'gs1' && $m->secretKeys() === ['password'] && !$m->multiple, 'plugin manifest: one account, password kept secret');
check(!str_contains((string) file_get_contents(dirname(__DIR__) . '/src/Services/Gs1.php'), 'PasePlugin'), 'core never imports plugin classes');

$calls = [];
$r = $gs1->sync();
check($r['total'] === 3 && $r['linked'] === 2 && !$r['truncated'], 'sync reads all pages and links by EAN (also GTIN-14 with leading zero)');
check(count(array_filter($calls, static fn($c) => $c[0] !== 'GET')) === 0, 'sync only reads from GS1');
check(!$gs1->syncDue() && $gs1->lastSync() !== null, 'sync time saved; no auto sync right after');
check($gs1->guessPrefix() === '5901234500', 'prefix guessed from GS1 cards (capped at 10 digits)');

$ov = $gs1->overview();
$byGtin = array_column($ov['cards'], null, 'gtin');
check((int) $byGtin['5901234500012']['product_id'] === 1 && (int) $byGtin['5901234500029']['product_id'] === 2, 'cards show the CRM product');
check($byGtin['5901234500036']['product_id'] === null && (int) $byGtin['5901234500036']['sku_match']['id'] === 5, 'card without EAN in CRM suggests product by internal symbol = SKU');
check(array_column($ov['without_ean'], 'sku') === ['CC-1L', 'VAN-1L'] && array_column($ov['foreign'], 'sku') === ['LAV-500'], 'products without EAN and with foreign EAN');
check($gs1->cardFor('5901234500029')['suggestions'] === '["Dodaj zdjęcie produktu"]', 'GS1 quality suggestions kept');

// Wpisanie EAN z karty po SKU (po kliknięciu).
$pushed = [];
$push = static function (array $p, string $g) use (&$pushed): ?string { $pushed[] = [$p['sku'], $g]; return null; };
check($gs1->linkEan(5, '5901234500036', $push) === null && $pdo->query('SELECT ean FROM products WHERE id = 5')->fetchColumn() === '5901234500036'
    && $pushed === [['VAN-1L', '5901234500036']], 'link EAN from GS1 card to product by SKU, pushed to shop');
try { $gs1->linkEan(1, '5901234500036'); check(false, 'link over existing EAN'); } catch (RuntimeException) { check(true, 'link never overwrites an existing EAN'); }

// Kolejny wolny numer: z prefiksu, najwyższy znany + 1.
check($gs1->nextFreeGtin() === null, 'no prefix -> no proposal');
$gs1 = $setCfg(['prefix' => '590123450']);
check($gs1->nextFreeGtin() === '5901234500043', 'next free GTIN after the highest used one, with check digit');
$def = $gs1->formDefaults($pdo->query('SELECT * FROM products WHERE id = 3')->fetch());
check($def['gtin'] === '5901234500043' && $def['brandName'] === 'Lumio' && $def['gpcCode'] === '10000511' && $def['netContentUnit'] === 'l'
    && $def['internalSymbol'] === 'CC-1L' && $def['productWebsite'] === 'https://sklep.test/cc' && str_starts_with($def['description'], 'Wosk zapachowy'), 'form defaults from product and GS1 cards (GPC: most common real code, temporary 99999999 skipped)');

// Nadanie numeru: walidacja lokalna, numer zajęty w GS1, sukces.
$form = array_merge($def, ['netContent' => '1', 'variant' => 'Klementynka']);
$calls = [];
try { $gs1->assign(3, ['gtin' => '5901234500044'] + $form); check(false, 'bad check digit'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'cyfrą kontrolną'), 'bad check digit rejected before any call'); }
try { $gs1->assign(3, ['gpcCode' => '12'] + $form); check(false, 'bad gpc'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'GPC'), 'form validation (GPC)'); }
try { $gs1->assign(3, ['gpcCode' => '99999999'] + $form); check(false, 'temporary gpc'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'tymczasowa'), 'temporary GPC 99999999 refused for a new card'); }
check($calls === [], 'nothing sent to GS1 when the form is invalid');
try { $gs1->assign(3, ['gtin' => '5901234500036'] + $form); check(false, 'taken in CRM'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'VAN-1L'), 'number used by another CRM product rejected'); }
$cards[] = ['type' => 'products', 'id' => '5901234500043', 'attributes' => ['name' => 'Ktoś inny']];   // ktoś właśnie zajął numer w portalu
try { $gs1->assign(3, $form); $ok = false; } catch (RuntimeException $e) { $ok = str_contains($e->getMessage(), "zajęty w MojeGS1"); } check($ok, "number taken in GS1 is never overwritten");
check(count(array_filter($calls, static fn($c) => $c[0] === 'PUT')) === 0, 'no PUT for a taken number');
array_pop($cards);
$pushed = [];
$res = $gs1->assign(3, $form, $push);
$put = array_values(array_filter($calls, static fn($c) => $c[0] === 'PUT'));
$sent = json_decode((string) $put[0][3], true);
check($res['gtin'] === '5901234500043' && $res['suggestions'] === ['Dodaj zdjęcie'] && str_ends_with($put[0][1], '/api/v2/products/5901234500043'), 'assign creates the GS1 card');
check($sent['data']['id'] === '5901234500043' && $sent['data']['type'] === 'products' && $sent['data']['attributes']['brandName'] === 'Lumio'
    && $sent['data']['attributes']['gpcCode'] === 10000511 && $sent['data']['attributes']['netContent'] == 1 && $sent['data']['attributes']['targetMarket'] === ['PL']
    && $sent['data']['attributes']['status'] === 'ACT' && !isset($sent['data']['attributes']['subBrandName']), 'card payload matches the MojeGS1 schema');
check($pdo->query('SELECT ean FROM products WHERE id = 3')->fetchColumn() === '5901234500043' && $pushed === [['CC-1L', '5901234500043']]
    && $gs1->cardFor('5901234500043') !== null, 'GTIN saved as product EAN, pushed to shop, card cached');
check($gs1->nextFreeGtin() === '5901234500050', 'next proposal moves on');
try { $gs1->assign(3, ['gtin' => '5901234500050'] + $form); check(false, 'second GTIN'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'ma już EAN'), 'product with EAN never gets a second number'); }

// Błędy walidacji z GS1 czytelne po polsku.
$msg = Gs1Client::errorMessage(400, ['status' => 400, 'title' => 'Błąd walidacji', 'errors' => [['field' => 'data.attributes.brandName', 'message' => 'Pole musi mieć co najmniej 2 znaki']]]);
check(str_contains($msg, 'Marka: Pole musi mieć co najmniej 2 znaki'), 'GS1 validation errors shown with Polish field names');

// Wyszukiwarka na stronie GS1.
$ov = $gs1->overview();
check(array_column(Gs1::search($ov['cards'], 'vanilla'), 'gtin') === ['5901234500036'], 'search by GS1 name');
check(array_column(Gs1::search($ov['cards'], '500029'), 'gtin') === ['5901234500029'], 'search by part of GTIN');
check(array_column(Gs1::search($ov['cards'], 'GB-1L'), 'gtin') === ['5901234500012'], 'search by SKU of the linked CRM product');
check(array_column(Gs1::search($ov['foreign'], '4006381333931'), 'sku') === ['LAV-500'] && Gs1::search($ov['cards'], 'lumio nieistnieje') === [], 'search foreign EAN; every word must match');
check(count(Gs1::search($ov['cards'], '  ')) === count($ov['cards']), 'empty search returns everything');

// Link do karty w systemie GS1.
check($gs1->cardUrl('05901234500012') === 'https://www.gs1.org/services/verified-by-gs1/results?gtin=5901234500012', 'without a card address: link to Verified by GS1');
$gs1 = $setCfg(['card_url' => 'https://mojegs1.pl/produkty/{gtin}']);
check($gs1->cardUrl('5901234500012') === 'https://mojegs1.pl/produkty/5901234500012', 'card address from plugin settings opens the card in MojeGS1');
$gs1 = $setCfg(['card_url' => 'javascript:alert(1)//{gtin}']);
check(str_starts_with($gs1->cardUrl('5901234500012'), Gs1::VERIFY_URL), 'only https card addresses are used');

echo "\n$checks checks passed\n";
