<?php
declare(strict_types=1);

namespace PasePlugin\Tiktokshop;

use Pase\Support\Http;
use Pase\Support\Logger;

/**
 * Klient TikTok Shop Open API (wersja 202309, https://partner.tiktokshop.com/docv2).
 *
 * Połączenie: aplikacja w TikTok Shop Partner Center (app_key, app_secret, service_id). Sprzedawca zgadza się na dostęp
 * na stronie autoryzacji TikTok, wraca do CRM z kodem, który wymieniamy na token (ważny ok. 7 dni, odnawiany refresh_tokenem).
 *
 * Każde żądanie do open-api jest podpisane: parametry zapytania (bez sign i access_token) posortowane po kluczu
 * i sklejone jako klucz+wartość, przed nimi ścieżka, po nich treść JSON, całość owinięta app_secret z obu stron,
 * HMAC-SHA256 kluczem app_secret (hex). Token idzie w nagłówku x-tts-access-token, sklep w parametrze shop_cipher.
 *
 *  - GET  /authorization/202309/shops                                  sklepy, do których mamy dostęp (cipher)
 *  - POST /order/202309/orders/search                                  zamówienia (po dacie aktualizacji)
 *  - POST /product/202309/products/search                              produkty ze SKU i stanami w magazynach
 *  - POST /product/202309/products/{id}/inventory/update               stan SKU w magazynie TikTok
 *  - GET  /logistics/202309/delivery_options/{id}/shipping_providers   przewoźnicy dla sposobu dostawy zamówienia
 *  - POST /fulfillment/202309/orders/{id}/packages                     „wysłane" z numerem przesyłki (wysyłka sprzedawcy)
 */
final class TiktokShopClient
{
    public const API_URL  = 'https://open-api.tiktokglobalshop.com';
    public const AUTH_URL = 'https://auth.tiktok-shops.com';
    /** Strona zgody sprzedawcy - osobna dla USA. */
    public const AUTHORIZE_URLS = [
        'global' => 'https://services.tiktokshop.com/open/authorize',
        'us'     => 'https://services.us.tiktokshop.com/open/authorize',
    ];

    /** @var callable(string,string,array,?string):array{0:int,1:string} */
    private $transport;

    /**
     * @param array<string,mixed> $config app_key, app_secret, service_id, region (z konta integracji)
     * @param string|null $accessToken token sprzedawcy (tabela integration_accounts)
     * @param string|null $shopCipher  identyfikator sklepu z /authorization/202309/shops
     * @param ?callable $transport atrapa HTTP w testach: fn($method, $url, $headers, $body) => [status, body]
     */
    public function __construct(
        private readonly array $config,
        private readonly ?string $accessToken = null,
        private readonly ?string $shopCipher = null,
        ?callable $transport = null,
        private readonly ?\Closure $clock = null
    ) {
        $this->transport = $transport ?? static function (string $method, string $url, array $headers, ?string $body): array {
            $res = Http::request($method, $url, $headers, $body, 30);
            return [$res->status, $res->body];
        };
    }

    public function configured(): bool
    {
        return $this->appKey() !== '' && $this->appSecret() !== '';
    }

    public function appKey(): string
    {
        return trim((string) ($this->config['app_key'] ?? ''));
    }

    private function appSecret(): string
    {
        return trim((string) ($this->config['app_secret'] ?? ''));
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }

    /** Adres strony zgody sprzedawcy. $state wraca do nas w przekierowaniu (ochrona przed podrobionym powrotem). */
    public function authorizeUrl(string $state): string
    {
        $serviceId = trim((string) ($this->config['service_id'] ?? ''));
        if ($serviceId === '') {
            throw new \RuntimeException('Wpisz Service ID aplikacji (TikTok Shop Partner Center → App & Service → Twoja aplikacja).');
        }
        $base = self::AUTHORIZE_URLS[($this->config['region'] ?? 'global') === 'us' ? 'us' : 'global'];
        return $base . '?' . http_build_query(['service_id' => $serviceId, 'state' => $state]);
    }

    /**
     * Podpis żądania do open-api (patrz opis klasy).
     * @param array<string,scalar> $query
     */
    public static function sign(string $path, array $query, string $body, string $secret): string
    {
        unset($query['sign'], $query['access_token']);
        ksort($query, SORT_STRING);
        $base = $path;
        foreach ($query as $k => $v) {
            $base .= $k . $v;
        }
        $base = $secret . $base . $body . $secret;
        return hash_hmac('sha256', $base, $secret);
    }

    /** Kod autoryzacyjny -> token. @return array<string,mixed> data (access_token, access_token_expire_in, refresh_token, ...) */
    public function exchangeCode(string $code): array
    {
        return $this->token(['auth_code' => $code, 'grant_type' => 'authorized_code']);
    }

    /** @return array<string,mixed> data jak w exchangeCode() */
    public function refreshToken(string $refreshToken): array
    {
        return $this->token(['refresh_token' => $refreshToken, 'grant_type' => 'refresh_token']);
    }

    private function token(array $params): array
    {
        if (!$this->configured()) {
            throw new \RuntimeException('Brak App key / App secret aplikacji TikTok Shop.');
        }
        $path = $params['grant_type'] === 'refresh_token' ? '/api/v2/token/refresh' : '/api/v2/token/get';
        $url = self::AUTH_URL . $path . '?' . http_build_query(['app_key' => $this->appKey(), 'app_secret' => $this->appSecret()] + $params);
        [$status, $raw] = ($this->transport)('GET', $url, ['Accept' => 'application/json'], null);
        Logger::apiResponse('tiktok', 'GET', self::AUTH_URL . $path, (int) $status, 'token');
        $res = json_decode((string) $raw, true);
        $data = is_array($res) ? ($res['data'] ?? null) : null;
        if ((int) $status !== 200 || !is_array($res) || (int) ($res['code'] ?? -1) !== 0 || !is_array($data) || empty($data['access_token'])) {
            throw new \RuntimeException((int) $status === 0
                ? 'Brak połączenia z TikTok Shop (sprawdź internet na serwerze).'
                : 'TikTok Shop nie wydał tokenu: ' . self::errorText(is_array($res) ? $res : [], (int) $status));
        }
        return $data;
    }

    /**
     * Żądanie do open-api. Zwraca pole data odpowiedzi; błąd TikTok (code != 0) albo HTTP rzuca wyjątek z czytelnym opisem.
     * @param array<string,scalar> $query parametry zapytania endpointu (bez app_key/timestamp/sign/shop_cipher)
     * @return array<string,mixed>
     */
    public function call(string $method, string $path, array $query = [], ?array $body = null): array
    {
        if (!$this->configured()) {
            throw new \RuntimeException('Brak App key / App secret aplikacji TikTok Shop.');
        }
        if ($this->accessToken === null || $this->accessToken === '') {
            throw new \RuntimeException('Konto TikTok Shop nie jest połączone - kliknij „Połącz z TikTok Shop”.');
        }
        $query['app_key'] = $this->appKey();
        $query['timestamp'] = (string) $this->now();
        // Autoryzacja (lista sklepów) działa bez sklepu; reszta API wymaga shop_cipher.
        if (!str_starts_with($path, '/authorization/')) {
            if ($this->shopCipher === null || $this->shopCipher === '') {
                throw new \RuntimeException('Brak wybranego sklepu TikTok Shop - połącz konto jeszcze raz.');
            }
            $query['shop_cipher'] = $this->shopCipher;
        }
        // Treść podpisujemy dokładnie w tej postaci, w jakiej ją wysyłamy.
        $json = $body !== null ? json_encode($body === [] ? new \stdClass() : $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $query['sign'] = self::sign($path, $query, $json ?? '', $this->appSecret());
        $url = self::API_URL . $path . '?' . http_build_query($query);
        $headers = ['x-tts-access-token' => (string) $this->accessToken, 'Content-Type' => 'application/json', 'Accept' => 'application/json'];

        [$status, $raw] = ($this->transport)($method, $url, $headers, $json);
        Logger::apiResponse('tiktok', $method, self::API_URL . $path, (int) $status, '');
        $res = json_decode((string) $raw, true);
        if ((int) $status === 0) {
            throw new \RuntimeException('Brak połączenia z TikTok Shop (sprawdź internet na serwerze).');
        }
        if (!is_array($res) || (int) ($res['code'] ?? -1) !== 0 || (int) $status >= 400) {
            throw new TiktokShopException(self::errorText(is_array($res) ? $res : [], (int) $status), (int) ($res['code'] ?? 0));
        }
        return is_array($res['data'] ?? null) ? $res['data'] : [];
    }

    /** Czytelny opis błędu TikTok (kod + komunikat + request_id do zgłoszenia w TikTok). */
    public static function errorText(array $res, int $status): string
    {
        $known = [
            105001 => 'nieprawidłowy albo wygasły token - połącz konto jeszcze raz',
            105002 => 'token wygasł - połącz konto jeszcze raz',
            106001 => 'nieprawidłowy podpis żądania - sprawdź App secret',
            36009004 => 'brak uprawnień aplikacji do tego zakresu API (Partner Center → Twoja aplikacja → Manage API)',
        ];
        $code = (int) ($res['code'] ?? 0);
        $msg = trim((string) ($res['message'] ?? ''));
        $out = $known[$code] ?? ($msg !== '' ? $msg : 'HTTP ' . $status);
        if ($code !== 0) {
            $out .= ' (kod ' . $code . ')';
        }
        if (!empty($res['request_id'])) {
            $out .= ', request_id ' . $res['request_id'];
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> sklepy: id, name, region, cipher, code */
    public function shops(): array
    {
        $data = $this->call('GET', '/authorization/202309/shops');
        return is_array($data['shops'] ?? null) ? $data['shops'] : [];
    }

    /**
     * Strona zamówień zmienionych od $updatedFrom (unix), od najstarszej zmiany.
     * @return array{orders:array<int,array<string,mixed>>,next:string}
     */
    public function searchOrders(int $updatedFrom, string $pageToken = '', int $pageSize = 50): array
    {
        $query = ['page_size' => $pageSize, 'sort_field' => 'update_time', 'sort_order' => 'ASC'];
        if ($pageToken !== '') {
            $query['page_token'] = $pageToken;
        }
        $data = $this->call('POST', '/order/202309/orders/search', $query, ['update_time_ge' => $updatedFrom]);
        return ['orders' => is_array($data['orders'] ?? null) ? $data['orders'] : [], 'next' => (string) ($data['next_page_token'] ?? '')];
    }

    /** @return array{products:array<int,array<string,mixed>>,next:string} */
    public function searchProducts(string $pageToken = '', int $pageSize = 100): array
    {
        $query = ['page_size' => $pageSize];
        if ($pageToken !== '') {
            $query['page_token'] = $pageToken;
        }
        $data = $this->call('POST', '/product/202309/products/search', $query, ['status' => 'ALL']);
        return ['products' => is_array($data['products'] ?? null) ? $data['products'] : [], 'next' => (string) ($data['next_page_token'] ?? '')];
    }

    /** Ustawia stan jednego SKU w jednym magazynie TikTok. */
    public function updateInventory(string $productId, string $skuId, string $warehouseId, int $quantity): void
    {
        $inv = ['quantity' => max(0, $quantity)];
        if ($warehouseId !== '') {
            $inv = ['warehouse_id' => $warehouseId] + $inv;
        }
        $this->call('POST', '/product/202309/products/' . rawurlencode($productId) . '/inventory/update', [],
            ['skus' => [['id' => $skuId, 'inventory' => [$inv]]]]);
    }

    /** @return array<int,array{id:string,name:string}> przewoźnicy dostępni dla sposobu dostawy zamówienia */
    public function shippingProviders(string $deliveryOptionId): array
    {
        $data = $this->call('GET', '/logistics/202309/delivery_options/' . rawurlencode($deliveryOptionId) . '/shipping_providers');
        $out = [];
        foreach ((array) ($data['shipping_providers'] ?? []) as $p) {
            if (is_array($p) && isset($p['id'])) {
                $out[] = ['id' => (string) $p['id'], 'name' => (string) ($p['name'] ?? '')];
            }
        }
        return $out;
    }

    /**
     * Oznacza pozycje zamówienia jako wysłane z numerem przesyłki (wysyłka własna sprzedawcy).
     * @param string[] $lineItemIds
     * @return array<string,mixed> data (package_id)
     */
    public function markShipped(string $orderId, string $trackingNumber, string $providerId, array $lineItemIds): array
    {
        return $this->call('POST', '/fulfillment/202309/orders/' . rawurlencode($orderId) . '/packages', [], [
            'tracking_number'      => $trackingNumber,
            'shipping_provider_id' => $providerId,
            'order_line_item_ids'  => array_values($lineItemIds),
        ]);
    }
}
