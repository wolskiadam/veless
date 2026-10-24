<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Rozszerzenie KSeF (extensions/ksef): szyfrowanie wymagane przez KSeF API 2.0, faktura FA(3) zgodna ze schematem MF,
// mapowanie zamówienia na fakturę i cała wysyłka na atrapie KSeF (logowanie tokenem, sesja, status, UPO).
$root = dirname(__DIR__);
$temp = sys_get_temp_dir() . '/crm-ksef-' . bin2hex(random_bytes(6));
define('PASE_ROOT', $temp);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'Pase\\')) { require $root . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
    if (str_starts_with($class, 'PaseExt\\Ksef\\')) { require $root . '/extensions/ksef/' . substr($class, 13) . '.php'; }
});

use Pase\Automation\Catalog;
use Pase\Plugin\ExtensionRegistry;
use Pase\Plugin\ExtensionSettings;
use Pase\Plugin\Hooks;
use Pase\Repository\SettingsRepository;
use Pase\Support\SecretStore;
use PaseExt\Ksef\Fa3Xml;
use PaseExt\Ksef\Invoices;
use PaseExt\Ksef\InvoicePdf;
use PaseExt\Ksef\InvoiceView;
use PaseExt\Ksef\KsefApi;
use PaseExt\Ksef\KsefApiException;
use PaseExt\Ksef\KsefCrypto;
use PaseExt\Ksef\KsefExtension;
use PaseExt\Ksef\KsefService;
use PaseExt\Ksef\OrderInvoice;
use PaseExt\Ksef\Pdf;
use PaseExt\Ksef\Purchases;
use PaseExt\Ksef\QrCode;

// Funkcje panelu, których używa sekcja karty zamówienia.
function roleCanEdit(): bool { return true; }
function csrfToken(): string { return 'csrf-test'; }

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }
function throwsLike(callable $fn, string $needle): bool
{
    try { $fn(); } catch (Throwable $e) { if (str_contains($e->getMessage(), $needle)) { return true; } echo '   (komunikat: ' . $e->getMessage() . ")\n"; return false; }
    return false;
}

/** Odwrotność RSAES-OAEP (SHA-256/MGF1-SHA-256) - tak, jak robi to KSeF po swojej stronie. */
function oaepDecrypt(string $cipher, OpenSSLAsymmetricKey $private): string
{
    if (!openssl_private_decrypt($cipher, $em, $private, OPENSSL_NO_PADDING)) { throw new RuntimeException('RSA decrypt'); }
    $k = strlen($em);
    if ($em[0] !== "\0") { throw new RuntimeException('OAEP: zły pierwszy bajt'); }
    $maskedSeed = substr($em, 1, 32);
    $maskedDb = substr($em, 33);
    $seed = $maskedSeed ^ KsefCrypto::mgf1($maskedDb, 32);
    $db = $maskedDb ^ KsefCrypto::mgf1($seed, $k - 33);
    if (substr($db, 0, 32) !== hash('sha256', '', true)) { throw new RuntimeException('OAEP: zły lHash'); }
    $rest = ltrim(substr($db, 32), "\0");
    if ($rest === '' || $rest[0] !== "\x01") { throw new RuntimeException('OAEP: brak separatora'); }
    return substr($rest, 1);
}

foreach (['extensions', 'integrations', 'storage/logs', 'xsd'] as $d) { mkdir("$temp/$d", 0700, true); }
try {
    // ---------------- Kryptografia
    $private = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'Ministerstwo Finansow (test)'], $private, ['digest_alg' => 'sha256']);
    openssl_x509_export(openssl_csr_sign($csr, null, $private, 30, ['digest_alg' => 'sha256']), $certPem);
    $certB64 = preg_replace('/-----[^-]+-----|\s+/', '', $certPem);
    $public = KsefCrypto::publicKey($certB64);

    $c1 = KsefCrypto::rsaOaepSha256('tajny-token|1760000000123', $public);
    $c2 = KsefCrypto::rsaOaepSha256('tajny-token|1760000000123', $public);
    check(strlen($c1) === 256 && $c1 !== $c2, 'RSA-OAEP gives a 2048-bit ciphertext with a random seed');
    check(oaepDecrypt($c1, $private) === 'tajny-token|1760000000123', 'RSA-OAEP SHA-256 decrypts back to the token|timestamp string');
    $aesKey = random_bytes(32);
    check(oaepDecrypt(KsefCrypto::rsaOaepSha256($aesKey, $public), $private) === $aesKey, 'RSA-OAEP carries a 32-byte AES session key');

    // Niezależne sprawdzenie OpenSSL-em z wiersza poleceń (gdy jest): rsa_padding_mode:oaep + SHA-256 + MGF1-SHA-256.
    $cli = trim((string) shell_exec('command -v openssl 2>/dev/null'));
    if ($cli !== '') {
        openssl_pkey_export($private, $privPem);
        file_put_contents("$temp/k.pem", $privPem);
        file_put_contents("$temp/c.bin", $c1);
        $out = shell_exec('openssl pkeyutl -decrypt -inkey ' . escapeshellarg("$temp/k.pem") . ' -in ' . escapeshellarg("$temp/c.bin")
            . ' -pkeyopt rsa_padding_mode:oaep -pkeyopt rsa_oaep_md:sha256 -pkeyopt rsa_mgf1_md:sha256 2>&1');
        check($out === 'tajny-token|1760000000123', 'openssl pkeyutl (OAEP SHA-256) reads our ciphertext');
    }

    $iv = random_bytes(16);
    $enc = KsefCrypto::aesEncrypt('<Faktura/>', $aesKey, $iv);
    check(openssl_decrypt($enc, 'aes-256-cbc', $aesKey, OPENSSL_RAW_DATA, $iv) === '<Faktura/>' && strlen($enc) === 16, 'AES-256-CBC with PKCS#7 padding');
    check(KsefCrypto::sha256('abc') === 'ungWv48Bz+pBQUDeXa4iI7ADYaOWF3qctBD/YfIAFa0=' && KsefCrypto::sha256Url('abc') === 'ungWv48Bz-pBQUDeXa4iI7ADYaOWF3qctBD_YfIAFa0', 'SHA-256 in Base64 and Base64URL');

    // ---------------- FA(3) zgodna ze schematem MF
    foreach (glob("$root/tests/fixtures/ksef/*.xsd") as $f) { copy($f, "$temp/xsd/" . basename($f)); }
    $xsd = "$temp/xsd/schemat_FA(3)_v1-0E.xsd";
    file_put_contents($xsd, str_replace('schemaLocation="http://crd.gov.pl/xml/schematy/dziedzinowe/mf/2022/01/05/eD/DefinicjeTypy/StrukturyDanych_v10-0E.xsd"',
        'schemaLocation="StrukturyDanych_v10-0E.xsd"', (string) file_get_contents($xsd)));
    $schemaErrors = static function (string $xml) use ($xsd): array {
        libxml_use_internal_errors(true);
        libxml_clear_errors();
        $d = new DOMDocument();
        $d->loadXML($xml);
        $ok = $d->schemaValidate($xsd);
        $errs = array_map(static fn(LibXMLError $e): string => trim($e->message), libxml_get_errors());
        libxml_clear_errors();
        return $ok ? [] : ($errs ?: ['?']);
    };
    $seller = ['nip' => '5260250274', 'name' => 'Klub Testowy sp. z o.o.', 'address1' => 'ul. Prosta 1', 'address2' => '00-001 Warszawa', 'email' => 'biuro@example.pl', 'phone' => '+48 600 100 200'];
    $base = ['number' => 'KSEF/1/10/2026', 'issue_date' => '2026-10-14', 'currency' => 'PLN', 'seller' => $seller,
        'buyer' => ['name' => 'Jan Kowalski', 'address1' => 'ul. Krzywa 2', 'address2' => '31-001 Kraków', 'country' => 'PL', 'email' => 'jan@example.pl'],
        'lines' => [
            ['name' => 'Wosk <pszczeli> & co', 'qty' => 3, 'unit_gross' => 100 / 3, 'gross' => 100.0, 'rate' => '23'],
            ['name' => 'Poradnik', 'qty' => 1, 'unit_gross' => 50.0, 'gross' => 50.0, 'rate' => '5'],
            ['name' => 'Dostawa', 'qty' => 1, 'unit_gross' => 15.99, 'gross' => 15.99, 'rate' => '23'],
            ['name' => 'Próbka', 'qty' => 2, 'unit_gross' => 5.0, 'gross' => 10.0, 'rate' => '8'],
            ['name' => 'Eksport', 'qty' => 1, 'unit_gross' => 10.0, 'gross' => 10.0, 'rate' => '0'],
        ],
        'paid' => true, 'paid_date' => '2026-10-13', 'payment_form' => 6, 'order_number' => '#1234', 'order_date' => '2026-10-12', 'footer' => 'Dziękujemy'];
    $xml = Fa3Xml::build($base);
    check($schemaErrors($xml) === [], 'Consumer invoice (no NIP, 4 VAT rates, paid by transfer) matches FA(3) schema');
    $t = Fa3Xml::totals($base['lines']);
    check($t['rates']['23'] === ['gross' => 115.99, 'net' => 94.3, 'vat' => 21.69] && $t['rates']['5']['vat'] === 2.38 && $t['rates']['0']['vat'] === 0.0
        && $t['gross'] === 185.99, 'VAT is computed from gross sums per rate');
    check(str_contains($xml, '<P_13_1>94.30</P_13_1>') && str_contains($xml, '<P_14_1>21.69</P_14_1>') && str_contains($xml, '<P_13_6_1>10.00</P_13_6_1>')
        && str_contains($xml, '<P_15>185.99</P_15>') && str_contains($xml, '<BrakID>1</BrakID>') && str_contains($xml, '<P_9B>33.33333333</P_9B>')
        && str_contains($xml, 'Wosk &lt;pszczeli&gt; &amp; co') && str_contains($xml, '<P_12>0 KR</P_12>'), 'XML carries totals, buyer without ID, escaped names');

    $b2b = ['buyer' => ['nip' => '5260250274', 'name' => 'Firma Sp. z o.o.', 'address1' => 'ul. Firmowa 3', 'address2' => '00-950 Warszawa'], 'paid' => false,
        'payment_form' => 0, 'payment_desc' => 'Za pobraniem', 'bank_account' => '61 1090 1014 0000 0712 1981 2874', 'footer' => '', 'order_number' => ''] + $base;
    check($schemaErrors(Fa3Xml::build($b2b)) === [], 'Company invoice (NIP, unpaid, other payment, bank account) matches FA(3) schema');
    $eu = ['buyer' => ['eu_code' => 'DE', 'eu_vat' => '123456789', 'name' => 'Beispiel GmbH', 'address1' => 'Hauptstr. 1', 'address2' => '10115 Berlin', 'country' => 'DE'],
        'lines' => [['name' => 'Szkolenie', 'qty' => 1, 'unit_gross' => 200.0, 'gross' => 200.0, 'rate' => 'zw']], 'exempt_basis' => 'art. 43 ust. 1 pkt 29 ustawy o VAT'] + $base;
    check($schemaErrors(Fa3Xml::build($eu)) === [] && str_contains(Fa3Xml::build($eu), '<P_19A>art. 43'), 'EU buyer and VAT-exempt line match FA(3) schema');
    check(throwsLike(static fn() => Fa3Xml::build(['currency' => 'EUR'] + $base), 'tylko w PLN'), 'Foreign currency is refused');
    check(throwsLike(static fn() => Fa3Xml::build(['buyer' => ['nip' => '1234567890', 'name' => 'X']] + $base), 'NIP nabywcy'), 'Invalid buyer NIP is refused');
    check(throwsLike(static fn() => Fa3Xml::build(['seller' => ['nip' => ''] + $seller] + $base), 'NIP sprzedawcy'), 'Missing seller NIP is refused');
    check(throwsLike(static fn() => Fa3Xml::build(['lines' => [['name' => 'A', 'qty' => 1, 'unit_gross' => 1, 'gross' => 1, 'rate' => 'zw']]] + $base), 'podstawy zwolnienia'), 'Exempt line needs a basis');

    // ---------------- Baza: zamówienia, ustawienia
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
    $pdo->exec('CREATE TABLE woo_orders (id INTEGER PRIMARY KEY, woo_order_id INTEGER UNIQUE, order_number TEXT, status TEXT, currency TEXT, total REAL,
        customer_name TEXT, customer_email TEXT, date_created TEXT, payload TEXT, local_items TEXT NULL, local_invoice TEXT NULL, merged_into INTEGER NULL)');
    \Pase\Support\Runtime::setPdo($pdo);
    $addOrder = static function (int $id, array $payload, array $extra = []) use ($pdo): void {
        $pdo->prepare('INSERT INTO woo_orders (woo_order_id, order_number, currency, total, customer_name, customer_email, date_created, payload, local_items, local_invoice)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$id, (string) $id, 'PLN', (float) ($payload['total'] ?? 0), 'Jan Kowalski', 'jan@example.pl',
            '2026-10-12 10:00:00', json_encode($payload), $extra['local_items'] ?? null, $extra['local_invoice'] ?? null]);
    };
    $woo = ['currency' => 'PLN', 'total' => '138.00', 'payment_method' => 'bacs', 'payment_method_title' => 'Przelew', 'date_paid' => '2026-10-13 08:00:00',
        'billing' => ['first_name' => 'Jan', 'last_name' => 'Kowalski', 'company' => 'Pasieka Kowalski', 'address_1' => 'ul. Lipowa 5', 'address_2' => '',
            'postcode' => '30-001', 'city' => 'Kraków', 'country' => 'PL', 'email' => 'jan@example.pl', 'phone' => '600700800'],
        'meta_data' => [['key' => '_billing_nip', 'value' => '526-025-02-74']],
        'line_items' => [
            ['name' => 'Wosk 1 kg', 'quantity' => 2, 'total' => '81.30', 'total_tax' => '18.70', 'product_id' => 1, 'sku' => 'W1'],
            ['name' => 'Miód', 'quantity' => 1, 'total' => '20.37', 'total_tax' => '1.63', 'product_id' => 2, 'sku' => 'M1'],
        ],
        'shipping_lines' => [['method_title' => 'Kurier', 'total' => '13.01', 'total_tax' => '2.99']]];
    $addOrder(101, $woo);
    $allegro = ['currency' => 'PLN', 'total' => '59.99', 'buyer' => ['login' => 'kupujacy'], 'payment_method_title' => 'PayU', 'date_paid' => null,
        'billing' => ['first_name' => 'Anna', 'last_name' => 'Nowak', 'address_1' => 'ul. Polna 1', 'postcode' => '00-002', 'city' => 'Warszawa', 'country' => 'PL'],
        'line_items' => [['name' => 'Świeca', 'quantity' => 1, 'price' => 49.99, 'total' => '49.99']],
        'shipping_lines' => [['method_title' => 'Paczkomat', 'total' => '10.00']]];
    $addOrder(102, $allegro);

    $settings = ['environment' => 'test', 'nip' => '526-025-02-74', 'seller_name' => 'Klub Testowy sp. z o.o.', 'seller_address1' => 'ul. Prosta 1',
        'seller_address2' => '00-001 Warszawa', 'number_format' => 'KSEF/{nr}/{mm}/{rrrr}', 'default_vat' => '23', 'vat_mode' => 'vat', 'bank_account' => '61109010140000071219812874'];
    $now = new DateTimeImmutable('2026-10-14 12:00', new DateTimeZone('Europe/Warsaw'));
    $inv = (new OrderInvoice($pdo, $settings))->build($pdo->query('SELECT * FROM woo_orders WHERE woo_order_id = 101')->fetch(), $now);
    check($inv['buyer']['nip'] === '5260250274' && $inv['buyer']['name'] === 'Pasieka Kowalski' && $inv['buyer']['address2'] === '30-001 Kraków',
        'Woo order: buyer company with NIP from order meta');
    check(array_column($inv['lines'], 'rate') === ['23', '8', '23'] && array_column($inv['lines'], 'gross') === [100.0, 22.0, 16.0]
        && $inv['lines'][0]['unit_gross'] === 50.0, 'Woo order: gross lines with VAT rate from net/tax, shipping included');
    check($inv['paid'] && $inv['paid_date'] === '2026-10-13' && $inv['payment_form'] === 6 && $inv['bank_account'] === '' && $inv['order_number'] === '#101'
        && $inv['issue_date'] === '2026-10-14', 'Woo order: paid by transfer, order number and issue date');
    check($schemaErrors(Fa3Xml::build($inv + ['number' => 'X/1'])) === [], 'Invoice from a Woo order matches FA(3) schema');
    $inv2 = (new OrderInvoice($pdo, $settings))->build($pdo->query('SELECT * FROM woo_orders WHERE woo_order_id = 102')->fetch(), $now);
    check($inv2['buyer']['nip'] === '' && array_column($inv2['lines'], 'rate') === ['23', '23'] && !$inv2['paid'] && $inv2['payment_desc'] === 'PayU'
        && $inv2['bank_account'] === '61109010140000071219812874', 'Allegro order: no NIP, default VAT, unpaid with bank account');
    check($schemaErrors(Fa3Xml::build($inv2 + ['number' => 'X/2'])) === [], 'Invoice from an Allegro order matches FA(3) schema');
    $edited = (new OrderInvoice($pdo, $settings))->build(['woo_order_id' => 1, 'payload' => json_encode($woo),
        'local_items' => json_encode([['name' => 'Wosk 1 kg', 'quantity' => 1, 'total' => '40.65', 'product_id' => 1, 'sku' => 'W1']]),
        'local_invoice' => json_encode(['wants' => true, 'name' => 'Hans Müller', 'company' => '', 'address_1' => 'Hauptstr. 1', 'postcode' => '10115',
            'city' => 'Berlin', 'country' => 'DE', 'nip' => 'DE123456789'])], $now);
    check($edited['lines'][0]['gross'] === 50.0 && $edited['lines'][0]['rate'] === '23', 'Items edited in CRM keep VAT from the shop line');
    check($edited['buyer']['eu_code'] === 'DE' && $edited['buyer']['eu_vat'] === '123456789' && $edited['buyer']['nip'] === '', 'EU buyer gets KodUE + NrVatUE');
    check(throwsLike(static fn() => (new OrderInvoice($pdo, $settings))->build(['woo_order_id' => 9, 'merged_into' => 5, 'payload' => '{}']), 'scalone'), 'Merged order points to the main order');

    // ---------------- Numeracja
    $invoices = new Invoices($pdo);
    check($invoices->nextNumber('test', 'KSEF/{nr}/{mm}/{rrrr}', $now) === 'KSEF/1/10/2026' && $invoices->nextNumber('test', 'KSEF/{nr}/{mm}/{rrrr}', $now) === 'KSEF/2/10/2026'
        && $invoices->nextNumber('test', 'KSEF/{nr}/{mm}/{rrrr}', $now->modify('+1 month')) === 'KSEF/1/11/2026'
        && $invoices->nextNumber('prod', 'KSEF/{nr}/{mm}/{rrrr}', $now) === 'KSEF/1/10/2026', 'Monthly numbering, separate per environment');
    check($invoices->nextNumber('test', 'FV {nr}/{rrrr}', $now) === 'FV 1/2026' && $invoices->nextNumber('test', 'FV {nr}/{rrrr}', $now->modify('+1 month')) === 'FV 2/2026', 'Yearly numbering without {mm}');
    check(throwsLike(static fn() => $invoices->nextNumber('test', 'FV/{mm}', $now), '{nr}'), 'Number format must contain {nr}');
    $pdo->exec('DELETE FROM ext_ksef_counters');

    // ---------------- Atrapa KSeF API 2.0
    $ksef = new class($private, $certB64, $schemaErrors) {
        public array $log = [];
        public array $sessions = [];
        public array $invoices = [];
        public string $verdict = 'accept';
        public int $authPolls = 0;
        public bool $rotateOnce = false;
        /** Faktury otrzymane: lista metadanych (permanentStorageDate) i XML; stronicowanie i limit wyniku jak w KSeF, tylko mniejsze. */
        public array $received = [];
        /** Faktury wystawione przez firmę (Subject1). */
        public array $issued = [];
        public ?string $hwm = null;
        public int $metaPage = 2;
        public int $metaCap = 100;
        public array $metaQueries = [];
        public function __construct(private OpenSSLAsymmetricKey $private, private string $cert, private Closure $schemaErrors) {}
        public function __invoke(string $method, string $url, array $headers, ?string $body): array
        {
            $path = substr($url, strlen('https://api-test.ksef.mf.gov.pl/v2/'));
            $this->log[] = "$method $path";
            $in = $body !== null ? json_decode($body, true) : null;
            $auth = $headers['Authorization'] ?? '';
            $json = static fn(int $s, mixed $d): array => ['status' => $s, 'body' => json_encode($d)];
            $now = gmdate('Y-m-d\TH:i:sP');
            switch (true) {
                case $method === 'GET' && $path === 'security/public-key-certificates':
                    $c = ['certificate' => $this->cert, 'validFrom' => gmdate('Y-m-d\TH:i:sP', time() - 86400), 'validTo' => gmdate('Y-m-d\TH:i:sP', time() + 86400)];
                    return $json(200, [$c + ['publicKeyId' => 'KEY-TOKEN', 'usage' => ['KsefTokenEncryption']], $c + ['publicKeyId' => 'KEY-SYM', 'usage' => ['SymmetricKeyEncryption']],
                        ['certificate' => 'stary', 'publicKeyId' => 'OLD', 'validFrom' => '2020-01-01T00:00:00+00:00', 'validTo' => '2021-01-01T00:00:00+00:00', 'usage' => ['KsefTokenEncryption']]]);
                case $method === 'POST' && $path === 'auth/challenge':
                    return $json(200, ['challenge' => '20261014-CR-1', 'timestamp' => $now, 'timestampMs' => 1760000000123]);
                case $method === 'POST' && $path === 'auth/ksef-token':
                    if ($this->rotateOnce) {
                        $this->rotateOnce = false;
                        return $json(400, ['exception' => ['exceptionDetailList' => [['exceptionCode' => 21470, 'exceptionDescription' => 'Przesłany identyfikator klucza jest nieznany.']]]]);
                    }
                    $plain = oaepDecrypt(base64_decode($in['encryptedToken']), $this->private);
                    if ($plain !== 'token-testowy|1760000000123' || $in['publicKeyId'] !== 'KEY-TOKEN' || $in['contextIdentifier'] !== ['type' => 'Nip', 'value' => '5260250274']
                        || $in['challenge'] !== '20261014-CR-1') {
                        return $json(400, ['exception' => ['exceptionDetailList' => [['exceptionCode' => 21405, 'exceptionDescription' => 'Błąd walidacji', 'details' => [$plain]]]]]);
                    }
                    return $json(202, ['referenceNumber' => 'AUTH-1', 'authenticationToken' => ['token' => 'AUTH-JWT', 'validUntil' => $now]]);
                case $method === 'GET' && $path === 'auth/AUTH-1':
                    return $auth !== 'Bearer AUTH-JWT' ? $json(401, []) : $json(200, ['status' => ['code' => ++$this->authPolls % 2 === 1 ? 100 : 200, 'description' => 'x']]);
                case $method === 'POST' && $path === 'auth/token/redeem':
                    return $auth !== 'Bearer AUTH-JWT' ? $json(401, []) : $json(200, ['accessToken' => ['token' => 'ACCESS-JWT', 'validUntil' => $now], 'refreshToken' => ['token' => 'R', 'validUntil' => $now]]);
                case $auth !== 'Bearer ACCESS-JWT':
                    return $json(401, ['title' => 'Unauthorized', 'detail' => 'Brak tokena dostępowego']);
                case $method === 'POST' && $path === 'sessions/online':
                    $ref = 'SES-' . (count($this->sessions) + 1);
                    $this->sessions[$ref] = ['key' => oaepDecrypt(base64_decode($in['encryption']['encryptedSymmetricKey']), $this->private),
                        'iv' => base64_decode($in['encryption']['initializationVector']), 'form' => $in['formCode'], 'keyId' => $in['encryption']['publicKeyId'], 'closed' => false];
                    return $json(201, ['referenceNumber' => $ref, 'validUntil' => $now]);
                case $method === 'POST' && preg_match('#^sessions/online/(SES-\d+)/invoices$#', $path, $m) === 1:
                    $s = $this->sessions[$m[1]];
                    $enc = base64_decode($in['encryptedInvoiceContent']);
                    $xml = openssl_decrypt($enc, 'aes-256-cbc', $s['key'], OPENSSL_RAW_DATA, $s['iv']);
                    if ($xml === false || $in['invoiceHash'] !== base64_encode(hash('sha256', $xml, true)) || $in['invoiceSize'] !== strlen($xml)
                        || $in['encryptedInvoiceHash'] !== base64_encode(hash('sha256', $enc, true)) || $in['encryptedInvoiceSize'] !== strlen($enc)) {
                        return $json(400, ['exception' => ['exceptionDetailList' => [['exceptionCode' => 21405, 'exceptionDescription' => 'Zły skrót lub rozmiar']]]]);
                    }
                    $ref = 'INV-' . (count($this->invoices) + 1);
                    $this->invoices[$ref] = ['session' => $m[1], 'xml' => $xml, 'schema' => ($this->schemaErrors)($xml)];
                    return $json(202, ['referenceNumber' => $ref]);
                case $method === 'POST' && preg_match('#^sessions/online/(SES-\d+)/close$#', $path, $m) === 1:
                    $this->sessions[$m[1]]['closed'] = true;
                    return ['status' => 204, 'body' => ''];
                case $method === 'GET' && preg_match('#^sessions/(SES-\d+)/invoices/(INV-\d+)$#', $path, $m) === 1:
                    $inv = $this->invoices[$m[2]];
                    if ($inv['schema'] !== [] || $this->verdict === 'reject') {
                        return $json(200, ['referenceNumber' => $m[2], 'status' => ['code' => 450, 'description' => 'Błąd weryfikacji semantyki dokumentu faktury',
                            'details' => $inv['schema'] ?: ['Nieprawidłowy NIP nabywcy']]]);
                    }
                    if ($this->verdict === 'wait') {
                        return $json(200, ['referenceNumber' => $m[2], 'status' => ['code' => 150, 'description' => 'Trwa przetwarzanie']]);
                    }
                    return $json(200, ['referenceNumber' => $m[2], 'ksefNumber' => '5260250274-20261014-0100A0B1C2D3-4E', 'acquisitionDate' => $now,
                        'status' => ['code' => 200, 'description' => 'Sukces']]);
                case $method === 'POST' && preg_match('#^invoices/query/metadata\?(.*)$#', $path, $m) === 1:
                    parse_str($m[1], $qs);
                    $r = $in['dateRange'];
                    $this->metaQueries[] = $r + ['page' => (int) $qs['pageOffset'], 'pageSize' => (int) $qs['pageSize'], 'sort' => $qs['sortOrder'], 'subject' => $in['subjectType']];
                    $from = new DateTimeImmutable($r['from']);
                    $to = isset($r['to']) ? new DateTimeImmutable($r['to']) : new DateTimeImmutable('2100-01-01');
                    if (!empty($r['restrictToPermanentStorageHwmDate']) && $this->hwm !== null) {
                        $to = min($to, new DateTimeImmutable($this->hwm));
                    }
                    $field = $r['dateType'] === 'Issue' ? 'issueDate' : 'permanentStorageDate';
                    if (!in_array($in['subjectType'], ['Subject1', 'Subject2'], true) || !in_array($r['dateType'], ['PermanentStorage', 'Issue'], true) || $to->getTimestamp() - $from->getTimestamp() > 100 * 86400) {
                        return $json(400, ['exception' => ['exceptionDetailList' => [['exceptionCode' => 21405, 'exceptionDescription' => 'Zły filtr']]]]);
                    }
                    $hits = array_values(array_filter(array_map(static fn(array $f): array => $f['meta'], $in['subjectType'] === 'Subject1' ? $this->issued : $this->received), static function (array $mt) use ($from, $to, $field): bool {
                        $d = new DateTimeImmutable($mt[$field] . ($field === 'issueDate' ? 'T12:00:00+00:00' : ''));
                        return $d >= $from && $d <= $to;
                    }));
                    usort($hits, static fn(array $a, array $b): int => strcmp($a[$field], $b[$field]));
                    $capped = array_slice($hits, 0, $this->metaCap);
                    $pageRows = array_slice($capped, (int) $qs['pageOffset'] * $this->metaPage, $this->metaPage);
                    return $json(200, ['hasMore' => count($capped) > ((int) $qs['pageOffset'] + 1) * $this->metaPage, 'isTruncated' => count($hits) > $this->metaCap,
                        'permanentStorageHwmDate' => $this->hwm, 'invoices' => $pageRows]);
                case $method === 'GET' && preg_match('#^invoices/ksef/(.+)$#', $path, $m) === 1:
                    $file = $this->received[$m[1]] ?? $this->issued[$m[1]] ?? null;
                    return $file !== null ? ['status' => 200, 'body' => $file['xml']]
                        : $json(404, ['exception' => ['exceptionDetailList' => [['exceptionCode' => 21164, 'exceptionDescription' => 'Faktura nie istnieje']]]]);
                case $method === 'GET' && preg_match('#^sessions/(SES-\d+)/invoices/(INV-\d+)/upo$#', $path, $m) === 1:
                    return ['status' => 200, 'body' => '<?xml version="1.0"?><Potwierdzenie><NumerKSeFDokumentu>5260250274-20261014-0100A0B1C2D3-4E</NumerKSeFDokumentu></Potwierdzenie>'];
            }
            return $json(404, ['title' => 'Not found ' . $path]);
        }
    };
    $noSleep = static function (int $ms): void {};
    $factory = static fn(string $env): KsefApi => new KsefApi($env, $ksef, $noSleep);

    // Logowanie tokenem
    $api = $factory('test');
    check($api->login('5260250274', 'token-testowy') === 'ACCESS-JWT', 'Login: challenge, encrypted token, status polling (100 -> 200), redeem');
    check(throwsLike(static fn() => $factory('test')->login('5260250274', 'zly-token'), 'Błąd walidacji'), 'Rejected token surfaces the KSeF error text');
    $ksef->rotateOnce = true;
    $ksef->log = [];
    check($factory('test')->login('5260250274', 'token-testowy') === 'ACCESS-JWT'
        && count(array_keys($ksef->log, 'GET security/public-key-certificates', true)) === 2, 'Key rotation (21470) refetches MF certificates and retries');
    $e = KsefApiException::fromResponse(400, json_encode(['title' => 'Bad Request', 'errors' => [['code' => 21184, 'description' => 'Sesja tymczasowo niedostępna']]]), 'x');
    check($e->codes === [21184] && str_contains($e->getMessage(), 'Sesja tymczasowo niedostępna'), 'Problem-details errors are readable too');
    check(throwsLike(static fn() => new KsefApi('produkcja'), 'nieznane środowisko'), 'Unknown environment is refused');

    // ---------------- Wysyłka e-faktury z zamówienia
    $service = new KsefService($pdo, $settings, 'token-testowy', $factory, $noSleep);
    check($service->missing() === [] && (new KsefService($pdo, ['nip' => '1'] + $settings, '', $factory))->missing() === ['NIP firmy', 'token KSeF'], 'Missing settings are listed');
    $p = $service->preview(101);
    check($p['error'] === null && $p['totals']['gross'] === 138.0, 'Preview shows the invoice without sending');
    $ksef->log = [];
    $row = $service->issue(101, 'Ala');
    $sent = $ksef->invoices['INV-1'];
    check($row['status'] === 'accepted' && $row['ksef_number'] === '5260250274-20261014-0100A0B1C2D3-4E' && str_contains((string) $row['upo'], 'Potwierdzenie')
        && $row['number'] === 'KSEF/1/' . date('m/Y') && $row['created_by'] === 'Ala', 'Issue: invoice accepted, KSeF number and UPO saved');
    check($sent['schema'] === [] && $sent['xml'] === $row['xml'] && $ksef->sessions['SES-1']['closed'] && $ksef->sessions['SES-1']['keyId'] === 'KEY-SYM'
        && $ksef->sessions['SES-1']['form'] === ['systemCode' => 'FA (3)', 'schemaVersion' => '1-0E', 'value' => 'FA'], 'KSeF received valid FA(3), encrypted with the session key; session closed');
    check(count(array_keys($ksef->log, 'POST auth/challenge', true)) === 1, 'One login per operation');
    check(throwsLike(static fn() => $service->issue(101, 'Ala'), 'ma już e-fakturę'), 'Second invoice for the same order is blocked');
    $url = KsefService::verificationUrl('test', '5260250274', '2026-10-14', (string) $row['xml']);
    check($url === 'https://qr-test.ksef.mf.gov.pl/invoice/5260250274/14-10-2026/' . KsefCrypto::sha256Url((string) $row['xml']), 'Verification link (KOD I)');

    // Odrzucenie i ponowienie z tym samym numerem
    $ksef->verdict = 'reject';
    $row2 = $service->issue(102, 'Ala');
    check($row2['status'] === 'rejected' && str_contains((string) $row2['message'], 'Nieprawidłowy NIP nabywcy') && $row2['number'] === 'KSEF/2/' . date('m/Y'), 'Rejected invoice keeps the reason');
    $ksef->verdict = 'wait';
    $row3 = $service->issue(102, 'Ala');
    check($row3['status'] === 'processing' && $row3['number'] === $row2['number'], 'Retry after rejection reuses the invoice number');
    check(throwsLike(static fn() => $service->issue(102, 'Ala'), 'sprawdzana przez KSeF'), 'Invoice being processed blocks a second send');
    $ksef->verdict = 'accept';
    check((new KsefService($pdo, $settings, 'token-testowy', $factory, $noSleep))->refreshOpen() === 1 && $invoices->get(102, 'test')['status'] === 'accepted', 'Refresh picks up the KSeF number later');

    // Błąd transportu: status error, ponowienie możliwe
    $pdo->exec("UPDATE woo_orders SET woo_order_id = 103, order_number = '103' WHERE woo_order_id = 102");
    $down = static fn(string $env): KsefApi => new KsefApi($env, static fn(): array => ['status' => 0, 'body' => 'Could not resolve host'], $noSleep);
    check(throwsLike(static fn() => (new KsefService($pdo, $settings, 'token-testowy', $down, $noSleep))->issue(103, 'Ala'), 'brak połączenia z KSeF')
        && $invoices->get(103, 'test')['status'] === 'error', 'Network failure leaves the invoice as error with a message');
    check((new KsefService($pdo, $settings, 'token-testowy', $factory, $noSleep))->issue(103, 'Ala')['status'] === 'accepted', 'Failed send can be retried');

    // ---------------- Faktury zakupowe: pobieranie listy z KSeF i podgląd
    $purchaseXml = Fa3Xml::build(['number' => 'FV 7/10/2026', 'seller' => ['nip' => '7740001454', 'name' => 'Hurtownia Pszczelarska', 'address1' => 'ul. Miodowa 4',
        'address2' => '87-100 Toruń'], 'buyer' => ['nip' => '5260250274', 'name' => 'Klub Testowy sp. z o.o.', 'address1' => 'ul. Prosta 1', 'address2' => '00-001 Warszawa'],
        'paid' => false, 'payment_form' => 6, 'payment_desc' => '', 'bank_account' => '61 1090 1014 0000 0712 1981 2874', 'footer' => 'Termin 14 dni', 'order_number' => ''] + $base);
    $receive = static function (string $ksefNumber, string $stored, string $number = 'FV 7/10/2026') use ($ksef, $purchaseXml): void {
        $ksef->received[$ksefNumber] = ['xml' => $purchaseXml, 'meta' => ['ksefNumber' => $ksefNumber, 'invoiceNumber' => $number, 'issueDate' => substr($stored, 0, 10),
            'invoicingDate' => $stored, 'acquisitionDate' => $stored, 'permanentStorageDate' => $stored, 'seller' => ['nip' => '7740001454', 'name' => 'Hurtownia Pszczelarska'],
            'buyer' => ['identifier' => ['type' => 'Nip', 'value' => '5260250274'], 'name' => 'Klub Testowy'], 'netAmount' => 150.38, 'vatAmount' => 35.61, 'grossAmount' => 185.99,
            'currency' => 'PLN', 'invoicingMode' => 'Online', 'invoiceType' => 'Vat', 'formCode' => ['systemCode' => 'FA (3)', 'schemaVersion' => '1-0E', 'value' => 'FA'],
            'isSelfInvoicing' => false, 'hasAttachment' => false, 'invoiceHash' => KsefCrypto::sha256($purchaseXml)]];
    };
    $receive('7740001454-20260801-AAAAAAAAAAAA-01', '2026-08-01T10:00:00.000+00:00', 'FV 1/08/2026');
    $receive('7740001454-20260920-AAAAAAAAAAAA-02', '2026-09-20T10:00:00.000+00:00', 'FV 2/09/2026');
    $receive('7740001454-20261001-AAAAAAAAAAAA-03', '2026-10-01T10:00:00.000+00:00', 'FV 3/10/2026');
    $receive('7740001454-20261002-AAAAAAAAAAAA-04', '2026-10-02T10:00:00.000+00:00', 'FV 4/10/2026');
    $receive('7740001454-20261010-AAAAAAAAAAAA-05', '2026-10-10T10:00:00.000+00:00', 'FV 5/10/2026');
    $receive('7740001454-20261014-AAAAAAAAAAAA-06', '2026-10-14T11:00:00.000+00:00', 'FV 7/10/2026');
    $ksef->hwm = '2026-10-14T11:30:00.000+00:00';
    $ksef->metaCap = 3;
    $buyerSettings = ['do_sales' => '0', 'do_purchases' => '1', 'purchases_days' => '30'] + $settings;
    $buyer = new KsefService($pdo, $buyerSettings, 'token-testowy', $factory, $noSleep);
    $clock = new DateTimeImmutable('2026-10-14T12:00:00+00:00');
    check(!$buyer->salesEnabled() && $buyer->purchasesEnabled() && $service->salesEnabled() && !$service->purchasesEnabled(), 'Settings choose what the plugin does (sales on, purchases off by default)');
    check((new KsefService($pdo, ['seller_name' => '', 'seller_address1' => ''] + $buyerSettings, 'token-testowy', $factory))->missingConnection() === [],
        'Purchases need only the NIP and the token');
    $ksef->metaQueries = [];
    $r = $buyer->syncPurchases($clock);
    $purchases = $buyer->purchaseInvoices();
    check($r === ['new' => 5, 'seen' => 6, 'more' => false] && count($purchases->latest('test')) === 5 && $purchases->get('test', '7740001454-20260801-AAAAAAAAAAAA-01') === null,
        'First fetch takes the last 30 days, pages through results and resumes after a truncated result');
    $q0 = $ksef->metaQueries[0];
    check($q0['subject'] === 'Subject2' && $q0['dateType'] === 'PermanentStorage' && $q0['from'] === '2026-09-14T12:00:00.000+00:00' && $q0['restrictToPermanentStorageHwmDate'] === true
        && $q0['pageSize'] === 250 && $q0['sort'] === 'Asc' && $ksef->metaQueries[2]['from'] === '2026-10-02T10:00:00.000+00:00', 'Query: buyer invoices by storage date with HWM, restart from the last stored date');
    check($purchases->syncPoint('test') === '2026-10-14T11:30:00.000+00:00', 'Next fetch starts at the KSeF high water mark');
    $row = $purchases->get('test', '7740001454-20261014-AAAAAAAAAAAA-06');
    check($row['invoice_number'] === 'FV 7/10/2026' && $row['seller_nip'] === '7740001454' && (float) $row['gross'] === 185.99 && $row['issue_date'] === '2026-10-14' && $row['xml'] === null,
        'Invoice metadata is stored without downloading the file');
    $receive('7740001454-20261014-AAAAAAAAAAAA-07', '2026-10-14T11:45:00.000+00:00', 'FV 8/10/2026');
    $ksef->hwm = '2026-10-14T11:50:00.000+00:00';
    $ksef->metaCap = 100;
    check($buyer->syncPurchases($clock->modify('+30 minutes')) === ['new' => 1, 'seen' => 1, 'more' => false], 'Next fetch brings only new invoices');
    $purchases->setSyncPoint('test', '2026-01-01T00:00:00.000+00:00');
    $ksef->metaQueries = [];
    $r = $buyer->syncPurchases($clock->modify('+30 minutes'));
    $spans = array_map(static fn(array $q): int => (new DateTimeImmutable($q['to']))->getTimestamp() - (new DateTimeImmutable($q['from']))->getTimestamp(), $ksef->metaQueries);
    check($r['new'] === 1 && $purchases->get('test', '7740001454-20260801-AAAAAAAAAAAA-01') !== null && max($spans) <= 90 * 86400
        && $ksef->metaQueries[1]['from'] === $ksef->metaQueries[0]['to'], 'Long gap is fetched in back-to-back windows of at most 90 days');
    check(count($purchases->latest('test', 'hurtownia', '2026-10')) === 5 && count($purchases->latest('test', 'FV 8/')) === 1, 'Purchase list filters by text and month');
    $months = $purchases->months('test');
    $all = $purchases->totals('test');
    $oct = $purchases->totals('test', '', '2026-10');
    $page2 = $purchases->latest('test', '', '', 2, 2);
    check(array_key_first($months) === '2026-10' && array_sum($months) === $all['count'] && $oct['count'] === $months['2026-10']
        && count($page2) === 2 && $page2[0]['ksef_number'] === $purchases->latest('test', '', '', 3)[2]['ksef_number']
        && abs($all['sums']['PLN']['gross'] - array_sum(array_map(static fn(array $r): float => (float) $r['gross'], $purchases->latest('test')))) < 0.001,
        'Purchase list counts months, sums all pages and pages with offset');
    check(throwsLike(static fn() => (new KsefService($pdo, $buyerSettings, '', $factory))->syncPurchases(), 'token KSeF'), 'Fetch without token explains what is missing');

    // Pobieranie z wybranego okresu (data wystawienia), bez ruszania punktu „Pobierz nowe”
    $pdo->exec('DELETE FROM ext_ksef_purchases');
    $pointBefore = $purchases->syncPoint('test');
    $ksef->metaQueries = [];
    $r = $buyer->fetchPurchases('2026-09-01', '2026-10-05', $clock);
    $q = $ksef->metaQueries[0];
    check($r === ['new' => 3, 'seen' => 3, 'more' => false, 'until' => null] && $q['dateType'] === 'Issue' && $q['from'] === '2026-09-01T00:00:00.000+02:00'
        && $ksef->metaQueries[array_key_last($ksef->metaQueries)]['to'] === '2026-10-06T00:00:00.000+02:00' && !isset($q['restrictToPermanentStorageHwmDate'])
        && $purchases->get('test', '7740001454-20261002-AAAAAAAAAAAA-04') !== null && $purchases->get('test', '7740001454-20261010-AAAAAAAAAAAA-05') === null,
        'Period fetch takes invoices issued between the two dates (whole days, Polish time)');
    check($purchases->syncPoint('test') === $pointBefore, 'Period fetch does not move the "new invoices" point');
    $ksef->metaQueries = [];
    $r = $buyer->fetchPurchases('2025-01-01', '2026-10-14', $clock);
    $spans = array_map(static fn(array $q): int => (new DateTimeImmutable($q['to']))->getTimestamp() - (new DateTimeImmutable($q['from']))->getTimestamp(), $ksef->metaQueries);
    check($r['new'] === 4 && $r['seen'] === 7 && !$r['more'] && max($spans) <= 90 * 86400 + 3600 && $ksef->metaQueries[0]['from'] === '2025-01-01T00:00:00.000+01:00'
        && $ksef->metaQueries[array_key_last($ksef->metaQueries)]['to'] === '2026-10-14T14:00:00.000+02:00', 'Long period is fetched in 90-day windows (Polish days, so +1 h across DST), up to now');
    check(throwsLike(static fn() => $buyer->fetchPurchases('2026-10-05', '2026-10-01'), 'późniejsza') && throwsLike(static fn() => $buyer->fetchPurchases('wczoraj', ''), 'dwie daty'),
        'Period must be two dates in order');

    $ksef->log = [];
    $got = $buyer->purchaseXml('7740001454-20261014-AAAAAAAAAAAA-06');
    $again = (new KsefService($pdo, $buyerSettings, 'token-testowy', $factory, $noSleep))->purchaseXml('7740001454-20261014-AAAAAAAAAAAA-06');
    check($got === $purchaseXml && $again === $purchaseXml && count(array_keys($ksef->log, 'GET invoices/ksef/7740001454-20261014-AAAAAAAAAAAA-06', true)) === 1,
        'Invoice XML is downloaded once and kept');
    $view = InvoiceView::parse($purchaseXml);
    check($view['seller']['name'] === 'Hurtownia Pszczelarska' && $view['buyer']['nip'] === '5260250274' && $view['number'] === 'FV 7/10/2026' && count($view['lines']) === 5
        && $view['total'] === '185.99' && $view['payment']['account'] === '61109010140000071219812874' && $view['rates'][0]['label'] === '23% / 22%', 'Invoice view reads parties, lines, totals and payment from FA(3)');
    $viewHtml = InvoiceView::html($purchaseXml);
    check(str_contains($viewHtml, 'Wosk &lt;pszczeli&gt; &amp; co') && str_contains($viewHtml, '185,99 PLN') && str_contains($viewHtml, 'NIP 7740001454') && !str_contains($viewHtml, '<pszczeli>'),
        'Invoice view HTML is escaped');
    $qrUrl = 'https://qr-test.ksef.mf.gov.pl/invoice/7740001454/14-10-2026/UtQp9uXsmT0dGQe1MWBu8nbSSv2K9XBdEDVtq4ZcVyI';
    $qr = QrCode::encode($qrUrl);
    $bits = '';
    for ($y = 0; $y < $qr->size; $y++) {
        for ($x = 0; $x < $qr->size; $x++) {
            $bits .= $qr->module($x, $y) ? '1' : '0';
        }
    }
    check($bits === trim((string) file_get_contents(__DIR__ . '/fixtures/ksef/qr.txt')) && str_starts_with($qr->svg(), '<svg'), 'QR code matches the reference encoder (qrcodegen.js)');
    $viewHtml = InvoiceView::html($purchaseXml, ['ksef' => '7740001454-20261014-AAAAAAAAAAAA-06', 'qr' => $qrUrl]);
    check(str_contains($viewHtml, 'Faktura podstawowa') && str_contains($viewHtml, 'Kwota należności ogółem') && str_contains($viewHtml, 'Podsumowanie stawek podatku')
        && str_contains($viewHtml, '7740001454-20261014-AAAAAAAAAAAA-06') && str_contains($viewHtml, '<svg') && str_contains($viewHtml, 'href="' . $qrUrl . '"'),
        'Invoice view follows the KSeF layout with number, sections and QR code');
    $pdfBody = InvoicePdf::render($purchaseXml, ['ksef' => '7740001454-20261014-AAAAAAAAAAAA-06', 'qr' => $qrUrl]);
    $xrefOk = preg_match('/startxref\n(\d+)\n%%EOF\n$/', $pdfBody, $mx) === 1 && substr($pdfBody, (int) $mx[1], 4) === 'xref';
    if ($xrefOk && preg_match_all('/^(\d{10}) 00000 n $/m', substr($pdfBody, (int) $mx[1]), $offs)) {
        foreach ($offs[1] as $i => $off) {
            $xrefOk = $xrefOk && str_starts_with(substr($pdfBody, (int) $off), ($i + 1) . ' 0 obj');
        }
    }
    check(str_starts_with($pdfBody, '%PDF-1.4') && $xrefOk && str_contains($pdfBody, '/Count 1') && str_contains($pdfBody, '/BaseFont /Helvetica-Bold')
        && str_contains($pdfBody, "(Pr\x8Abka)") && str_contains($pdfBody, '/URI (' . $qrUrl . ')') && str_contains($pdfBody, '(185,99 PLN)'),
        'Invoice PDF is a valid PDF with Polish letters, totals and the KSeF link');
    $longXml = preg_replace_callback('/<FaWiersz>.*?<\/FaWiersz>/s', static fn(array $mm): string => str_repeat($mm[0], 25), $purchaseXml, 1);
    $longPdf = InvoicePdf::render((string) $longXml);
    check(str_contains($longPdf, '/Count 2') && str_contains($longPdf, '(Strona 2 z 2)') && substr_count($longPdf, "(Nazwa towaru lub us\x86ugi)") === 2,
        'Long invoice continues on the next page with the table header repeated');
    $pdfText = new Pdf();
    check(count($pdfText->wrap(str_repeat('x', 200), 50)) > 1 && $pdfText->wrap('Zażółć gęślą jaźń', 200) === ['Zażółć gęślą jaźń']
        && $pdfText->width('ł', 10) < $pdfText->width('ą', 10) && $pdfText->width('ą', 10, true) > 0,
        'PDF text wrapping and widths handle Polish letters and long words');
    check(throwsLike(static fn() => InvoiceView::parse('<a>'), 'Nie udało się') && throwsLike(static fn() => InvoiceView::parse('<Faktura/>'), 'brak elementu Fa'), 'Broken XML is reported, not shown');
    check(KsefService::verificationUrlForHash('test', '7740001454', '2026-10-14', KsefCrypto::sha256($purchaseXml))
        === KsefService::verificationUrl('test', '7740001454', '2026-10-14', $purchaseXml), 'Verification link from the hash on the KSeF list');

    // ---------------- Rozszerzenie w panelu: haki, strony, automatyzacja, token zaszyfrowany
    SecretStore::useKeyFile("$temp/totp.key");
    file_put_contents("$temp/totp.key", base64_encode(random_bytes(32)));
    exec('cp -r ' . escapeshellarg("$root/extensions/ksef") . ' ' . escapeshellarg("$temp/extensions/ksef"));
    ExtensionRegistry::useDir("$temp/extensions");
    $ext = ExtensionRegistry::all()['ksef'] ?? null;
    check($ext instanceof KsefExtension, 'Extension boots from extensions/ksef');
    ExtensionSettings::save($pdo, $ext->manifest(), $settings + ['seller_email' => 'biuro@example.pl', 'do_sales' => '1']);
    (new SettingsRepository($pdo))->setMany([KsefExtension::tokenKey('test') => 'token-testowy']);
    $stored = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'EXT_KSEF_TEST_TOKEN'")->fetchColumn();
    check(SecretStore::isSealed($stored) && !str_contains((string) $stored, 'token-testowy'), 'KSeF token is stored encrypted');
    check(KsefExtension::tokenKey('prod') === 'EXT_KSEF_PROD_TOKEN', 'Separate token per environment');
    ExtensionSettings::save($pdo, $ext->manifest(), $settings + ['do_sales' => '1', 'token_test' => 'token-z-ustawien']);
    $raw = (string) $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'EXT_KSEF'")->fetchColumn();
    ExtensionSettings::reset();
    check(!str_contains($raw, 'token-z-ustawien') && str_contains($raw, '"token_test":"enc:v1:') && ExtensionSettings::get('ksef', $pdo)['token_test'] === 'token-z-ustawien'
        && ExtensionSettings::get('ksef', $pdo)['nip'] === '526-025-02-74', 'Token typed in the plugin settings is stored encrypted and read back in plain');
    ExtensionSettings::save($pdo, $ext->manifest(), $settings + ['do_sales' => '1', 'token_test' => '']);
    check(ExtensionSettings::get('ksef', $pdo)['token_test'] === 'token-z-ustawien', 'Empty token field keeps the saved token');
    ExtensionSettings::save($pdo, $ext->manifest(), $settings + ['do_sales' => '1'], ['token_test' => '1']);
    check(ExtensionSettings::get('ksef', $pdo)['token_test'] === '', '"Remove saved" clears the token');
    ExtensionSettings::save($pdo, $ext->manifest(), $settings + ['do_sales' => '1', 'token_test' => 'token-testowy']);
    ExtensionRegistry::reset();
    check(Hooks::page('ksef.faktury') !== null && Hooks::page('ksef.wystaw')['role'] === 'editor' && Hooks::page('ksef.polaczenie')['role'] === 'admin'
        && !empty(Hooks::page('ksef.plik')['raw']), 'Pages: list, send, admin-only connection, raw file download');
    check(isset(Catalog::actions()['x_ksef_invoice']['run']), 'Automation action is registered');
    $sections = Hooks::applyFilters('order_view.sections', [], ['woo_order_id' => 101], []);
    $html = ($sections[0]['render'])(['woo_order_id' => 101], []);
    check($sections[0]['id'] === 'ksef' && str_contains($html, 'KSEF/1/') && str_contains($html, '5260250274-20261014-0100A0B1C2D3-4E')
        && str_contains($html, 'qr-test.ksef.mf.gov.pl/invoice/5260250274/') && str_contains($html, 'co=upo') && str_contains($html, 'Testowe'), 'Order card shows number, KSeF number, links');
    $html = ($sections[0]['render'])(['woo_order_id' => 555], []);
    check(str_contains($html, 'Podgląd i wysyłka do KSeF'), 'Order without e-invoice offers the send button');
    Hooks::doAction('orders.rows', [['woo_order_id' => 101], ['woo_order_id' => 7]]);
    $ind = Hooks::applyFilters('orders.indicators', [], ['woo_order_id' => 101]);
    check(($ind['x_ksef']['state'] ?? '') === 'ok' && Hooks::applyFilters('orders.indicators', [], ['woo_order_id' => 7]) === [], 'Order list icon for orders with e-invoice');

    // Tylko faktury zakupowe: bez sekcji karty zamówienia, ikon i automatyzacji; strony zakupów
    ExtensionSettings::save($pdo, $ext->manifest(), $buyerSettings);
    ExtensionRegistry::reset();
    check(Hooks::page('ksef.zakupy') !== null && Hooks::page('ksef.pobierz')['role'] === 'editor' && !empty(Hooks::page('ksef.zakupxml')['raw'])
        && Hooks::page('ksef.faktury') !== null && Hooks::page('ksef.wystaw') === null && Hooks::applyFilters('order_view.sections', [], ['woo_order_id' => 101], []) === []
        && !isset(Catalog::actions()['x_ksef_invoice']), 'Purchases only: purchase pages, no order card section or automation');
    $_GET = ['q' => 'FV 7'];
    $page = (Hooks::page('ksef.zakupy')['render'])($pdo, ['canEdit' => true, 'isAdmin' => true, 'csrf' => 'csrf-test', 'url' => '', 'post' => false]);
    check(str_contains($page, 'FV 7/10/2026') && !str_contains($page, 'FV 8/10/2026') && str_contains($page, 'Pobierz nowe z KSeF') && str_contains($page, 'k=7740001454-20261014-AAAAAAAAAAAA-06'),
        'Purchase list page with search and fetch button');
    $_GET = ['k' => '7740001454-20261014-AAAAAAAAAAAA-06'];
    $page = (Hooks::page('ksef.zakup')['render'])($pdo, ['canEdit' => true]);
    check(str_contains($page, 'Hurtownia Pszczelarska') && str_contains($page, 'Wosk &lt;pszczeli&gt;') && str_contains($page, 'qr-test.ksef.mf.gov.pl/invoice/7740001454/14-10-2026/'),
        'Purchase invoice page shows the invoice and the KSeF link');
    $_GET = [];
    // Faktury sprzedażowe z KSeF (firma jako sprzedawca, Subject1): osobna tabela i punkt pobierania, kontrahent = nabywca
    $saleXml = Fa3Xml::build(['number' => 'KSEF/9/10/2026', 'seller' => ['nip' => '5260250274', 'name' => 'Klub Testowy sp. z o.o.', 'address1' => 'ul. Prosta 1', 'address2' => '00-001 Warszawa'],
        'buyer' => ['nip' => '1132853869', 'name' => 'Pasieka Pod Lipą', 'address1' => 'Leśna 2', 'address2' => '05-500 Piaseczno'],
        'paid' => true, 'payment_form' => 6, 'payment_desc' => '', 'bank_account' => '', 'footer' => '', 'order_number' => ''] + $base);
    $ksef->issued['5260250274-20261010-BBBBBBBBBBBB-01'] = ['xml' => $saleXml, 'meta' => ['ksefNumber' => '5260250274-20261010-BBBBBBBBBBBB-01', 'invoiceNumber' => 'KSEF/9/10/2026',
        'issueDate' => '2026-10-10', 'permanentStorageDate' => '2026-10-10T09:00:00.000+00:00', 'seller' => ['nip' => '5260250274', 'name' => 'Klub Testowy'],
        'buyer' => ['identifier' => ['type' => 'Nip', 'value' => '1132853869'], 'name' => 'Pasieka Pod Lipą'], 'netAmount' => 100, 'vatAmount' => 23, 'grossAmount' => 123,
        'currency' => 'PLN', 'invoiceType' => 'Vat', 'invoiceHash' => KsefCrypto::sha256($saleXml)]];
    $ksef->metaQueries = [];
    $saleBuyer = new KsefService($pdo, $buyerSettings + ['do_sales_list' => '1'], 'token-testowy', $factory, $noSleep);
    $sr = $saleBuyer->syncInvoices(Purchases::SALE, $clock);
    $sales = $saleBuyer->ksefInvoices(Purchases::SALE);
    $saleRows = $sales->latest('test');
    check($sr['new'] === 1 && $ksef->metaQueries[0]['subject'] === 'Subject1' && count($saleRows) === 1 && $saleRows[0]['party_name'] === 'Pasieka Pod Lipą'
        && $saleRows[0]['party_nip'] === '1132853869' && $sales->syncPoint('test') !== null && $purchases->get('test', '5260250274-20261010-BBBBBBBBBBBB-01') === null
        && $saleBuyer->salesListEnabled() && count($sales->latest('test', 'Lipą')) === 1, 'Sales invoices are fetched as Subject1 into their own list, with the buyer as counterparty');
    check($saleBuyer->fetchInvoices(Purchases::SALE, '2026-10-01', '2026-10-14', $clock)['seen'] === 1 && end($ksef->metaQueries)['subject'] === 'Subject1'
        && $saleBuyer->invoiceXml(Purchases::SALE, '5260250274-20261010-BBBBBBBBBBBB-01') === $saleXml, 'Sales invoices: period fetch and XML download');
    $render = static fn(array $get): string => ($_GET = $get) !== null ? (Hooks::page('ksef.zakupy')['render'])($pdo, ['canEdit' => false, 'isAdmin' => false, 'csrf' => '', 'url' => '', 'post' => false]) : '';
    $newest = (string) array_key_first($purchases->months('test'));
    $page = $render([]);
    check(str_contains($page, '<option value="' . $newest . '" selected') && str_contains($page, 'Wszystkie miesiące') && !str_contains($page, 'aria-label="Paginacja"'),
        'Purchase list opens on the newest month with invoices');
    $ins = $pdo->prepare("INSERT INTO ext_ksef_purchases (env, ksef_number, invoice_number, issue_date, gross, currency, created_at) VALUES ('test', ?, ?, ?, 10, 'PLN', '2026-10-14 13:00:00')");
    for ($i = 1; $i <= 60; $i++) {
        $ins->execute([sprintf('9999999999-20260115-PAGE%08d-00', $i), sprintf('STY %02d/2026', $i), sprintf('2026-01-%02d', 1 + $i % 28)]);
    }
    $p1 = $render(['m' => '2026-01']);
    $p2 = $render(['m' => '2026-01', 'p' => '2']);
    check(substr_count($p1, 'page=ksef.zakup&amp;k=') === 50 && substr_count($p2, 'page=ksef.zakup&amp;k=') === 10 && str_contains($p1, 'strona 1 z 2')
        && str_contains($p1, 'm=2026-01&amp;p=2') && str_contains($p2, 'Razem (60, wszystkie strony)') && str_contains($p2, '600,00') && str_contains($p1, 'Styczeń 2026')
        && str_contains($p1, '2026-02') && !str_contains($p1, '2025-12'), 'Month view pages 50 invoices at a time with totals for the whole month');
    $p9 = $render(['m' => '2026-01', 'p' => '9']);
    check(str_contains($p9, 'strona 2 z 2') && str_contains($render(['m' => '2025-05']), 'Brak faktur wystawionych w tym miesiącu'), 'Out of range page and empty month are handled');
    check(Hooks::page('ksef.sprzedaz') === null, 'Sales list is off when unticked in settings');
    ExtensionSettings::save($pdo, $ext->manifest(), $buyerSettings + ['do_sales_list' => '1']);
    ExtensionRegistry::reset();
    $_GET = [];
    $sp = (Hooks::page('ksef.sprzedaz')['render'])($pdo, ['canEdit' => true, 'isAdmin' => false, 'csrf' => 'csrf-test', 'url' => '', 'post' => false]);
    $_GET = ['k' => '5260250274-20261010-BBBBBBBBBBBB-01'];
    $one = (Hooks::page('ksef.sprzedazfv')['render'])($pdo, ['canEdit' => true]);
    $salePdf = (Hooks::page('ksef.sprzedazpdf')['render'])($pdo);
    check(Hooks::page('ksef.sprzedaz')['title'] === '📤 Faktury sprzedażowe KSeF' && str_contains($sp, 'Pasieka Pod Lipą') && str_contains($sp, '<th>Nabywca</th>')
        && str_contains($sp, 'jest sprzedawcą') && str_contains($sp, 'page=ksef.pobierzsp') && str_contains($sp, 'k=5260250274-20261010-BBBBBBBBBBBB-01') && !str_contains($sp, 'Hurtownia')
        && str_contains($one, 'KSEF/9/10/2026') && str_contains($one, 'qr-test.ksef.mf.gov.pl/invoice/5260250274/10-10-2026/') && str_contains($one, 'page=ksef.sprzedazpdf')
        && str_starts_with($salePdf, '%PDF-1.4'), 'Sales list, invoice preview with our NIP in the KSeF link, and PDF');
    $_GET = [];

    // Automatyczne pobieranie z crona (hak cron.tick): interwał z ustawień, oba rodzaje list, błąd zapisany i bez pętli co minutę
    $store = new SettingsRepository($pdo);
    $autoSettings = $buyerSettings + ['do_sales_list' => '1', 'auto_every' => '60'];
    $auto = new KsefService($pdo, $autoSettings, 'token-testowy', $factory, $noSleep);
    $receive('7740001454-20261014-AAAAAAAAAAAA-08', '2026-10-14T12:10:00.000+00:00', 'FV 9/10/2026');
    $ksef->hwm = '2026-10-14T13:00:00.000+00:00';
    $t0 = $clock->modify('+2 hours');
    $a = $auto->autoSync($store, $t0);
    check($a !== null && $a['new'][Purchases::PURCHASE] === 1 && array_key_exists(Purchases::SALE, $a['new']) && $a['errors'] === []
        && (int) $store->get(KsefService::AUTO_AT_KEY) === $t0->getTimestamp() && json_decode((string) $store->get(KsefService::AUTO_LAST_KEY), true)['new'][Purchases::PURCHASE] === 1,
        'Auto fetch pulls new sales and purchase invoices and remembers when it ran');
    check($auto->autoSync($store, $t0->modify('+30 minutes')) === null && $auto->autoSync($store, $t0->modify('+60 minutes')) !== null, 'Auto fetch waits for the interval from the settings');
    check((new KsefService($pdo, ['auto_every' => '0'] + $autoSettings, 'token-testowy', $factory, $noSleep))->autoSync($store, $t0->modify('+1 day')) === null
        && (new KsefService($pdo, $autoSettings, '', $factory, $noSleep))->autoSync($store, $t0->modify('+1 day')) === null
        && (new KsefService($pdo, ['do_sales_list' => '0', 'do_purchases' => '0'] + $autoSettings, 'token-testowy', $factory, $noSleep))->autoSync($store, $t0->modify('+1 day')) === null
        && (new KsefService($pdo, $buyerSettings, 'token-testowy', $factory))->autoEvery() === 1440, 'Auto fetch is off when disabled, without a token or lists; once a day by default');
    $t1 = $t0->modify('+1 day');
    $bad = (new KsefService($pdo, $autoSettings, 'token-testowy', $down, $noSleep))->autoSync($store, $t1);
    check($bad !== null && count($bad['errors']) === 2 && str_contains($bad['errors'][0], 'brak połączenia z KSeF') && (int) $store->get(KsefService::AUTO_AT_KEY) === $t1->getTimestamp()
        && (new KsefService($pdo, $autoSettings, 'token-testowy', $down, $noSleep))->autoSync($store, $t1->modify('+1 minute')) === null, 'KSeF error is saved and not retried every minute');
    ExtensionSettings::save($pdo, $ext->manifest(), $autoSettings);
    ExtensionRegistry::reset();
    $_GET = [];
    $sp = (Hooks::page('ksef.sprzedaz')['render'])($pdo, ['canEdit' => true, 'isAdmin' => true, 'csrf' => 'csrf-test', 'url' => '', 'post' => false]);
    check(Hooks::hasAction('cron.tick') && str_contains($sp, 'Automatyczne pobieranie: co godzinę.') && str_contains($sp, 'brak połączenia z KSeF'),
        'Extension hooks into the cron and the list shows the last auto fetch with its error');
    ExtensionSettings::save($pdo, $ext->manifest(), ['auto_every' => '0'] + $autoSettings);
    ExtensionRegistry::reset();
    check(!Hooks::hasAction('cron.tick'), 'No cron hook when auto fetch is off');

    echo "\n{$checks} checks passed\n";
} finally {
    exec('rm -rf ' . escapeshellarg($temp));
}
