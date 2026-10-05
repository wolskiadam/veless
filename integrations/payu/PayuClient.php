<?php
declare(strict_types=1);

namespace PasePlugin\Payu;

use Pase\Support\Http;
use Pase\Support\Logger;

/**
 * Klient PayU Payouts API (REST v2_1).
 *
 * Logowanie: OAuth client_credentials kluczami punktu płatności sklepu (client_id = pos_id, client_secret).
 * Kwoty w API są w groszach (najmniejsza jednostka waluty), saldo sklepu przychodzi jako tekst, np. "220839" = 2208,39 PLN.
 *  - GET  /api/v2_1/shops/{shopId}       saldo: balance.total (całe) i balance.available (po blokadach / rezerwie wypłat)
 *  - POST /api/v2_1/payouts              wypłata na konto zapisane w PayU (payout.amount = część salda)
 *  - GET  /api/v2_1/payouts/{payoutId}   status wypłaty
 *  - POST /api/v2_1/orders               nowa płatność - link do strony płatności dla klienta (redirectUri)
 */
final class PayuClient
{
    public const URLS = ['production' => 'https://secure.payu.com', 'sandbox' => 'https://secure.snd.payu.com'];

    /** Czytelne komunikaty dla kodów błędów wypłat PayU. */
    private const ERRORS = [
        'NOT_ENOUGH_FUNDS' => 'Za mało środków dostępnych do wypłaty.',
        'PAYOUT_ALREADY_EXISTS' => 'Ta wypłata została już zlecona (ten sam identyfikator).',
        'UNAUTHORIZED_REQUEST' => 'PayU odrzuciło klucze OAuth. Sprawdź client_id i client_secret.',
        'UNKOWN_MERCHANT' => 'PayU nie rozpoznaje sklepu dla tych kluczy.',
        'INCORRECT_MERCHANT_POS' => 'Nieprawidłowy sklep albo punkt płatności. Sprawdź Id sklepu (shopId).',
        'INCORRECT_PAYOUT' => 'PayU nie zna takiej wypłaty.',
        'INCORRECT_REQUEST' => 'PayU odrzuciło zlecenie wypłaty.',
        'SERVICE_TEMPORARY_UNAVAILABLE' => 'PayU jest chwilowo niedostępne. Spróbuj za chwilę.',
        'DATA_NOT_FOUND' => 'PayU nie zna takiej płatności w tym sklepie.',
        'OPENAPI_REFUND_AMOUNT_TOO_HIGH' => 'Kwota zwrotu jest większa niż możliwa do zwrotu.',
        'OPENAPI_ERROR_REFUND_AMOUNT_TOO_HIGH' => 'Kwota zwrotu jest większa niż możliwa do zwrotu.',
        'PAYOUT_TOKEN_INSUFFICIENT_AUTHENTICATION_LEVEL' => 'Te klucze nie mają uprawnień do wypłat. Poproś PayU o włączenie wypłat przez API dla tego punktu płatności.',
    ];

    /** @var callable(string,string,array,?string):array{0:int,1:string} */
    private $transport;
    private ?string $token = null;

    /**
     * @param array<string,mixed> $config client_id, client_secret, shop_id, environment (z konta integracji)
     * @param ?callable $transport atrapa HTTP w testach: fn($method, $url, $headers, $body) => [status, body]
     */
    public function __construct(private readonly array $config, ?callable $transport = null)
    {
        $this->transport = $transport ?? static function (string $method, string $url, array $headers, ?string $body): array {
            $res = Http::request($method, $url, $headers, $body, 30);
            return [$res->status, $res->body];
        };
    }

    public function configured(): bool
    {
        foreach (['client_id', 'client_secret', 'shop_id'] as $k) {
            if (trim((string) ($this->config[$k] ?? '')) === '') {
                return false;
            }
        }
        return true;
    }

    public function sandbox(): bool
    {
        return ($this->config['environment'] ?? 'production') === 'sandbox';
    }

    public function baseUrl(): string
    {
        // Tylko testy HTTP (tests/payu_http.php): atrapa PayU na 127.0.0.1 ustawiona zmienną środowiskową serwera testowego.
        $test = (string) getenv('CRM_TEST_PAYU_URL');
        if ($test !== '' && preg_match('~^http://127\.0\.0\.1:\d+$~', $test)) {
            return $test;
        }
        return self::URLS[$this->sandbox() ? 'sandbox' : 'production'];
    }

    public function shopId(): string
    {
        return trim((string) ($this->config['shop_id'] ?? ''));
    }

    /** Token OAuth (client_credentials), trzymany do końca życia obiektu. */
    private function token(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }
        if (!$this->configured()) {
            throw new \RuntimeException('Brak danych dostępu do PayU. Wpisz je w Konfiguracja → Integracje → PayU.');
        }
        $url = $this->baseUrl() . '/pl/standard/user/oauth/authorize';
        $body = http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => trim((string) $this->config['client_id']),
            'client_secret' => trim((string) $this->config['client_secret']),
        ]);
        [$status, $raw] = ($this->transport)('POST', $url, ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'], $body);
        Logger::apiResponse('payu', 'POST', $url, (int) $status, 'oauth');
        $data = json_decode((string) $raw, true);
        $token = is_array($data) ? (string) ($data['access_token'] ?? '') : '';
        if ((int) $status !== 200 || $token === '') {
            throw new \RuntimeException((int) $status === 0
                ? 'Brak połączenia z PayU (sprawdź internet na serwerze).'
                : 'PayU nie wydało tokenu OAuth (HTTP ' . (int) $status . '). Sprawdź client_id i client_secret' . ($this->sandbox() ? ' (wybrane środowisko: sandbox).' : ' - klucze z sandboxa nie działają na produkcji.'));
        }
        return $this->token = $token;
    }

    /** @return array{0:int,1:array<string,mixed>} status HTTP i zdekodowany JSON (albo []) */
    public function request(string $method, string $path, ?array $body = null): array
    {
        $headers = ['Authorization' => 'Bearer ' . $this->token(), 'Accept' => 'application/json', 'Content-Type' => 'application/json'];
        $json = $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        [$status, $raw] = ($this->transport)($method, $this->baseUrl() . $path, $headers, $json);
        Logger::apiResponse('payu', $method, $this->baseUrl() . $path, (int) $status, '');
        $data = json_decode((string) $raw, true);
        return [(int) $status, is_array($data) ? $data : []];
    }

    /** Dane sklepu z saldem. @return array{0:int,1:array<string,mixed>} */
    public function shop(): array
    {
        return $this->request('GET', '/api/v2_1/shops/' . rawurlencode($this->shopId()));
    }

    /**
     * Zleca wypłatę części salda na konto bankowe zapisane w PayU.
     * @param int $amount kwota w groszach
     * @param string $extPayoutId nasz identyfikator - PayU odrzuci drugie zlecenie z tym samym (PAYOUT_ALREADY_EXISTS)
     * @return array{0:int,1:array<string,mixed>}
     */
    public function createPayout(int $amount, string $description, string $extPayoutId): array
    {
        $payout = ['extPayoutId' => $extPayoutId, 'amount' => $amount];
        if ($description !== '') {
            $payout['description'] = $description;
        }
        return $this->request('POST', '/api/v2_1/payouts', ['shopId' => $this->shopId(), 'payout' => $payout]);
    }

    /** @return array{0:int,1:array<string,mixed>} */
    public function getPayout(string $payoutId): array
    {
        return $this->request('GET', '/api/v2_1/payouts/' . rawurlencode($payoutId));
    }

    /** Zamówienie (płatność) w PayU po orderId nadanym przez PayU. @return array{0:int,1:array<string,mixed>} */
    public function getOrder(string $orderId): array
    {
        return $this->request('GET', '/api/v2_1/orders/' . rawurlencode($orderId));
    }

    /**
     * Nowa płatność (zamówienie w PayU) - link do strony płatności PayU dla klienta.
     * merchantPosId = client_id (id punktu płatności). PayU odpowiada 302 albo 201 z redirectUri (link) i orderId.
     * @param array<string,mixed> $order pola zamówienia PayU bez merchantPosId
     * @return array{0:int,1:array<string,mixed>}
     */
    public function createOrder(array $order): array
    {
        return $this->request('POST', '/api/v2_1/orders', ['merchantPosId' => trim((string) ($this->config['client_id'] ?? ''))] + $order);
    }

    /** Szczegóły transakcji zamówienia (metoda płatności, zamaskowana karta, bank). @return array{0:int,1:array<string,mixed>} */
    public function getTransactions(string $orderId): array
    {
        return $this->request('GET', '/api/v2_1/orders/' . rawurlencode($orderId) . '/transactions');
    }

    /** Zwroty zamówienia. @return array{0:int,1:array<string,mixed>} (lista zwrotów pod kluczem 'refunds' albo jako tablica) */
    public function getRefunds(string $orderId): array
    {
        return $this->request('GET', '/api/v2_1/orders/' . rawurlencode($orderId) . '/refunds');
    }

    /**
     * Zwrot pieniędzy kupującemu (całość albo część płatności).
     * @param int $amount kwota w groszach
     * @param string $extRefundId nasz identyfikator - chroni przed podwójnym zwrotem
     * @return array{0:int,1:array<string,mixed>}
     */
    public function createRefund(string $orderId, int $amount, string $description, string $extRefundId): array
    {
        return $this->request('POST', '/api/v2_1/orders/' . rawurlencode($orderId) . '/refunds', ['refund' => [
            'description' => $description !== '' ? $description : 'Zwrot',
            'amount' => (string) $amount,
            'extRefundId' => $extRefundId,
        ]]);
    }

    /** Test połączenia: token i odczyt sklepu. @return array{ok:bool,message:string} */
    public function test(): array
    {
        try {
            [$st, $data] = $this->shop();
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        if ($st !== 200) {
            return ['ok' => false, 'message' => self::errorMessage($st, $data)];
        }
        $b = (array) ($data['balance'] ?? []);
        return ['ok' => true, 'message' => 'Połączono z PayU' . ($this->sandbox() ? ' (sandbox)' : '') . ': sklep ' . ($data['name'] ?? $this->shopId())
            . ', dostępne do wypłaty ' . number_format(((int) ($b['available'] ?? 0)) / 100, 2, ',', ' ') . ' ' . ($b['currencyCode'] ?? $data['currencyCode'] ?? 'PLN') . '.'];
    }

    /** Komunikat błędu z odpowiedzi PayU (status.statusCode / codeLiteral / statusDesc). */
    public static function errorMessage(int $status, array $data): string
    {
        if ($status === 0) {
            return 'Brak połączenia z PayU (sprawdź internet na serwerze).';
        }
        $st = (array) ($data['status'] ?? []);
        $literal = (string) ($st['codeLiteral'] ?? $st['statusCode'] ?? '');
        if (isset(self::ERRORS[$literal])) {
            return self::ERRORS[$literal] . ' (PayU: ' . $literal . ')';
        }
        if ($status === 401) {
            return self::ERRORS['UNAUTHORIZED_REQUEST'];
        }
        $parts = array_filter([$literal, (string) ($st['code'] ?? ''), (string) ($st['statusDesc'] ?? '')], static fn(string $s): bool => $s !== '');
        return 'PayU (HTTP ' . $status . '): ' . ($parts ? implode(' · ', array_unique($parts)) : 'nieoczekiwana odpowiedź.');
    }
}
