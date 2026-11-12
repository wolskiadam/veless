<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Wiadomość ze strony zamówienia do kupującego z Allegro idzie przez Centrum wiadomości Allegro
// (nie e-mailem na @allegromail.pl, z którego Allegro wycinało treść). Atrapa klienta Allegro.
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'Pase\\')) { require $root . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Services\AllegroOrderMessage as M;

$checks = 0;
function ok(bool $condition, string $name): void { global $checks; if (!$condition) { throw new RuntimeException('FAIL: ' . $name); } ++$checks; echo "OK: {$name}\n"; }

$fake = new class {
    public array $sent = [];
    public array $uploads = [];
    public bool $fail = false;
    public function sendBuyerMessage(string $login, string $text, ?string $orderId = null, array $ids = []): array
    {
        if ($this->fail) { return ['ok' => false, 'message' => 'Nie można wysłać.']; }
        $this->sent[] = compact('login', 'text', 'orderId', 'ids');
        return ['ok' => true, 'message' => ''];
    }
    public function uploadMessageAttachment(string $name, string $mime, string $bytes): array
    {
        $this->uploads[] = compact('name', 'mime', 'bytes');
        return ['ok' => true, 'id' => 'att-1', 'message' => ''];
    }
};

$order = ['woo_order_id' => 9000000517, 'order_number' => 'cf-517', 'payload' => json_encode(['id' => 'cf-517', 'buyer' => ['login' => 'kupujacy_test', 'email' => 'x@allegromail.pl']])];
ok(M::isAllegro($order) && !M::isAllegro(['woo_order_id' => 501]), 'Allegro orders are recognised by their CRM number');
ok(M::buyerLogin($order) === 'kupujacy_test' && M::checkoutFormId($order) === 'cf-517', 'Buyer login and checkout form id come from the order');

$text = "Zamówienie jest w trakcie realizacji.\n\nZamówienie dotyczy produktów:\n\n2 × Dymna Świeca Zapachowa";
M::send($fake, $order, $text);
ok(count($fake->sent) === 1 && $fake->sent[0]['text'] === $text && $fake->sent[0]['login'] === 'kupujacy_test' && $fake->sent[0]['orderId'] === 'cf-517',
    'The full text goes to the buyer, attached to the order');

$fake->sent = [];
M::send($fake, $order, 'Faktura w załączniku.', ['name' => 'FV 1/2026.pdf', 'content' => '%PDF-1.4']);
ok($fake->uploads[0]['mime'] === 'application/pdf' && $fake->sent[0]['ids'] === ['att-1'], 'PDF goes as an Allegro message attachment');

$long = implode("\n", array_fill(0, 120, str_repeat('ą', 30)));
$parts = M::chunks($long);
ok(count($parts) === 2 && max(array_map('mb_strlen', $parts)) <= M::MAX_TEXT && implode("\n", $parts) === $long, 'Long text is split on line breaks within the Allegro limit');
$fake->sent = [];
M::send($fake, $order, $long, ['name' => 'a.png', 'content' => 'x']);
ok(count($fake->sent) === 2 && $fake->sent[0]['ids'] === [] && $fake->sent[1]['ids'] === ['att-1'], 'Attachment rides on the last part');

$err = static function (callable $fn): string { try { $fn(); return ''; } catch (RuntimeException $e) { return $e->getMessage(); } };
ok(str_contains($err(fn() => M::send($fake, $order, 'x', ['name' => 'a.docx', 'content' => 'x'])), 'tylko PDF i obrazy'), 'Unsupported attachment is refused before sending');
ok(str_contains($err(fn() => M::send($fake, ['woo_order_id' => 9000000001, 'payload' => '{}'], 'x')), 'brak loginu'), 'Missing buyer login is reported');
$fake->fail = true;
ok(str_contains($err(fn() => M::send($fake, $order, 'x')), 'Nie można wysłać.'), 'Allegro error is shown to the operator');

$noToken = new \Pase\Services\AllegroMessaging(new class {
    public function bearerToken(): ?string { return null; }
});
ok(str_contains($err(fn() => M::send($noToken, $order, 'x')), 'Brak tokenu Allegro'), 'Without an Allegro connection nothing is sent and the operator sees why');

// Goły adres sklepu („sklep.pl”, „https://sklep.pl/”) Allegro zatrzymuje; link do konkretnej strony (zamówienie z tokenem) przepuszcza.
ok(M::bareAddresses("Szczegóły: https://sklep.example.pl/zamowienie.php?token=abc123\n\nPozdrawiamy\nLumio") === [], 'Order page link with a token is not a bare address');
ok(M::bareAddresses("Pozdrawiamy\nlumio.pl") === ['lumio.pl'], 'Shop name written as a domain is a bare address');
ok(M::bareAddresses('Zapraszamy na https://lumio.pl/ oraz www.lumio.eu.') === ['https://lumio.pl/', 'www.lumio.eu'], 'Homepage links with and without protocol are found');
ok(M::bareAddresses('Śledzenie: https://inpost.pl/sledzenie-przesylek?number=1, opinia: https://g.page/r/ABC/review, kontakt@lumio.pl, allegro.pl, 12.50 zł, faktura.pdf') === [],
    'Tracking and review links, e-mails, Allegro, amounts and file names are not flagged');
$before = count($fake->sent);
ok(str_contains($err(fn() => M::send($fake, $order, "Dziękujemy!\nlumio.pl")), 'Allegro zatrzyma') && count($fake->sent) === $before,
    'A message with a bare shop address is not sent and the operator sees why');

echo "Wszystkie testy przeszły ({$checks}).\n";
