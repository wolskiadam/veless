<?php
declare(strict_types=1);

namespace PasePlugin\Woocommerce;

use Pase\Support\Http;
use Pase\Support\Logger;

/**
 * Klient API WooCommerce REST v3 — logika należąca do WTYCZKI (samowystarczalna).
 *
 * Uwierzytelnianie przez QUERY STRING (consumer_key/secret w URL) zamiast nagłówka
 * Authorization: Basic — na shared hostingu Apache często gubi ten nagłówek, a metoda
 * query-string przez HTTPS jest oficjalnie wspierana przez WooCommerce.
 *
 * Korzysta wyłącznie ze wspólnych narzędzi rdzenia (Http, Logger).
 */
final class WoocommerceClient
{
    public function __construct(private readonly array $config) {} // base_url, consumer_key, consumer_secret

    private function url(string $path, array $params = []): string
    {
        $params['consumer_key']    = $this->config['consumer_key'] ?? '';
        $params['consumer_secret'] = $this->config['consumer_secret'] ?? '';
        return ($this->config['base_url'] ?? '') . $path . '?' . http_build_query($params);
    }

    private function safeUrl(string $path): string
    {
        return ($this->config['base_url'] ?? '') . $path;
    }

    /** @return array{ok:bool, message:string, status:int} */
    public function testConnection(): array
    {
        $base = $this->config['base_url'] ?? '';
        if ($base === '') {
            return ['ok' => false, 'message' => 'Brak adresu sklepu (WOO_BASE_URL).', 'status' => 0];
        }
        if (($this->config['consumer_key'] ?? '') === '' || ($this->config['consumer_secret'] ?? '') === '') {
            return ['ok' => false, 'message' => 'Brak consumer key/secret.', 'status' => 0];
        }

        // Testujemy CHRONIONY endpoint /orders (wymaga realnego uwierzytelnienia).
        $res = Http::request('GET', $this->url('/wp-json/wc/v3/orders', ['per_page' => 1]));
        Logger::apiResponse('woo', 'GET', $this->safeUrl('/wp-json/wc/v3/orders'), $res->status, 'test połączenia');

        if ($res->status === 0) {
            return ['ok' => false, 'message' => 'Brak połączenia z serwerem sklepu (sprawdź adres / HTTPS).', 'status' => 0];
        }
        if ($res->isSuccess()) {
            return ['ok' => true, 'message' => 'Połączenie OK — klucze mają dostęp do zamówień.', 'status' => $res->status];
        }
        if ($res->status === 401) {
            return ['ok' => false, 'message' => 'Klucze nie mają dostępu do zamówień (401). Sprawdź rolę użytkownika klucza lub wtyczkę blokującą REST API.', 'status' => 401];
        }
        if ($res->status === 404) {
            return ['ok' => false, 'message' => 'Nie znaleziono REST API (404). Czy adres sklepu jest poprawny i WooCommerce aktywne?', 'status' => 404];
        }
        return ['ok' => false, 'message' => "Sklep odpowiedział kodem {$res->status}.", 'status' => $res->status];
    }

    /** @return array{status:int, body:string, count:int} diagnostyka /orders */
    public function debugFetchOrders(int $perPage = 5): array
    {
        $res = Http::request('GET', $this->url('/wp-json/wc/v3/orders', ['page' => 1, 'per_page' => $perPage]));
        $decoded = json_decode($res->body, true);
        return [
            'status' => $res->status,
            'body'   => mb_substr($res->body, 0, 4000),
            'count'  => is_array($decoded) ? count($decoded) : -1,
        ];
    }

    /** @return array{orders:array, isEmpty:bool, status:int} */
    public function fetchOrders(int $page, int $perPage = 50, string $status = 'any', ?string $after = null): array
    {
        $params = ['page' => $page, 'per_page' => min($perPage, 100), 'orderby' => 'id', 'order' => 'asc'];
        if ($status !== '' && $status !== 'any') {
            $params['status'] = $status;
        }
        if ($after !== null && $after !== '') {
            $params['after'] = $after;
        }

        $res = Http::request('GET', $this->url('/wp-json/wc/v3/orders', $params));
        Logger::apiResponse('woo', 'GET', $this->safeUrl('/wp-json/wc/v3/orders'), $res->status, "import page={$page}");

        if (!$res->isSuccess()) {
            throw new \RuntimeException("WooCommerce zwróciło HTTP {$res->status} przy pobieraniu zamówień.");
        }
        $orders = $res->json();
        return ['orders' => $orders, 'isEmpty' => count($orders) === 0, 'status' => $res->status];
    }

    /**
     * Zamówienia zmienione po $sinceGmt (nowe i ze zmienionym statusem), od najstarszej zmiany.
     * Tryb lokalny (AppMode) używa tego zamiast webhooków. Wymaga WooCommerce 5.8+ (modified_after).
     *
     * @return array<int,array<string,mixed>>
     */
    public function fetchOrdersModifiedSince(int $page, int $perPage, string $sinceGmt): array
    {
        $params = [
            'page' => $page, 'per_page' => min($perPage, 100), 'orderby' => 'modified', 'order' => 'asc',
            'modified_after' => $sinceGmt, 'dates_are_gmt' => 'true',
        ];
        $res = Http::request('GET', $this->url('/wp-json/wc/v3/orders', $params));
        Logger::apiResponse('woo', 'GET', $this->safeUrl('/wp-json/wc/v3/orders'), $res->status, "modified page={$page}");

        if (!$res->isSuccess()) {
            throw new \RuntimeException("WooCommerce zwróciło HTTP {$res->status} przy pobieraniu zmienionych zamówień.");
        }
        return $res->json();
    }

    /** @return array{products:array, isEmpty:bool, status:int} */
    public function fetchProducts(int $page, int $perPage = 50): array
    {
        $params = ['page' => $page, 'per_page' => min($perPage, 100), 'orderby' => 'id', 'order' => 'asc'];
        $res = Http::request('GET', $this->url('/wp-json/wc/v3/products', $params));
        Logger::apiResponse('woo', 'GET', $this->safeUrl('/wp-json/wc/v3/products'), $res->status, "products page={$page}");

        if (!$res->isSuccess()) {
            throw new \RuntimeException("WooCommerce zwróciło HTTP {$res->status} przy pobieraniu produktów.");
        }
        $products = $res->json();
        return ['products' => $products, 'isEmpty' => count($products) === 0, 'status' => $res->status];
    }

    /**
     * Warianty produktu wariantowego (type=variable). Sam produkt-rodzic zwykle
     * nie ma własnego SKU/ceny - te siedzą na poszczególnych wariantach.
     * @return array{variations:array, isEmpty:bool, status:int}
     */
    public function fetchVariations(int $parentId, int $page = 1, int $perPage = 100): array
    {
        $params = ['page' => $page, 'per_page' => min($perPage, 100), 'orderby' => 'id', 'order' => 'asc'];
        $res = Http::request('GET', $this->url("/wp-json/wc/v3/products/{$parentId}/variations", $params));
        Logger::apiResponse('woo', 'GET', $this->safeUrl("/wp-json/wc/v3/products/{$parentId}/variations"), $res->status, "variations page={$page}");

        if (!$res->isSuccess()) {
            throw new \RuntimeException("WooCommerce zwróciło HTTP {$res->status} przy pobieraniu wariantów produktu {$parentId}.");
        }
        $variations = $res->json();
        return ['variations' => $variations, 'isEmpty' => count($variations) === 0, 'status' => $res->status];
    }

    public function updateProductStock(int $wooProductId, int $stock): bool
    {
        $res = Http::request(
            'PUT',
            $this->url("/wp-json/wc/v3/products/{$wooProductId}"),
            ['Content-Type' => 'application/json'],
            ['manage_stock' => true, 'stock_quantity' => $stock]
        );
        Logger::apiResponse('woo', 'PUT', $this->safeUrl("/wp-json/wc/v3/products/{$wooProductId}"), $res->status, "stock={$stock}");
        return $res->isSuccess();
    }

    /**
     * Stan magazynowy KONKRETNEGO WARIANTU (nie produktu-rodzica) - endpoint wariantów
     * jest zagnieżdżony pod rodzicem, inaczej niż zwykły produkt.
     */
    public function updateVariationStock(int $parentId, int $variationId, int $stock): bool
    {
        $res = Http::request(
            'PUT',
            $this->url("/wp-json/wc/v3/products/{$parentId}/variations/{$variationId}"),
            ['Content-Type' => 'application/json'],
            ['manage_stock' => true, 'stock_quantity' => $stock]
        );
        Logger::apiResponse('woo', 'PUT', $this->safeUrl("/wp-json/wc/v3/products/{$parentId}/variations/{$variationId}"), $res->status, "stock={$stock}");
        return $res->isSuccess();
    }

    /** Cena regularna KONKRETNEGO WARIANTU - patrz updateVariationStock(). */
    public function updateVariationPrice(int $parentId, int $variationId, float $price): bool
    {
        $res = Http::request(
            'PUT',
            $this->url("/wp-json/wc/v3/products/{$parentId}/variations/{$variationId}"),
            ['Content-Type' => 'application/json'],
            ['regular_price' => number_format($price, 2, '.', '')]
        );
        Logger::apiResponse('woo', 'PUT', $this->safeUrl("/wp-json/wc/v3/products/{$parentId}/variations/{$variationId}"), $res->status, "price={$price}");
        return $res->isSuccess();
    }

    /** Aktualizuje cenę regularną produktu (regular_price). Cena jako string wg Woo. */
    public function updateProductPrice(int $wooProductId, float $price): bool
    {
        $res = Http::request(
            'PUT',
            $this->url("/wp-json/wc/v3/products/{$wooProductId}"),
            ['Content-Type' => 'application/json'],
            ['regular_price' => number_format($price, 2, '.', '')]
        );
        Logger::apiResponse('woo', 'PUT', $this->safeUrl("/wp-json/wc/v3/products/{$wooProductId}"), $res->status, "price={$price}");
        return $res->isSuccess();
    }

    /** Zmienia nazwę produktu w sklepie. Zwraca null albo komunikat błędu ze sklepu. */
    public function updateProductName(int $wooProductId, string $name): ?string
    {
        $path = "/wp-json/wc/v3/products/{$wooProductId}";
        $res = Http::request('PUT', $this->url($path), ['Content-Type' => 'application/json'], ['name' => $name]);
        Logger::apiResponse('woo', 'PUT', $this->safeUrl($path), $res->status, 'name');
        if ($res->isSuccess()) {
            return null;
        }
        $msg = json_decode($res->body, true)['message'] ?? '';
        return 'HTTP ' . $res->status . ($msg !== '' ? ': ' . strip_tags((string) $msg) : '');
    }

    /**
     * Wpisuje EAN/GTIN w pole „GTIN, UPC, EAN lub ISBN” (global_unique_id, WooCommerce 9.2+).
     * Wariant idzie na endpoint wariantu rodzica. Zwraca null albo komunikat błędu ze sklepu.
     */
    public function updateGlobalUniqueId(int $wooProductId, ?int $variationId, string $gtin): ?string
    {
        $path = $variationId ? "/wp-json/wc/v3/products/{$wooProductId}/variations/{$variationId}" : "/wp-json/wc/v3/products/{$wooProductId}";
        $res = Http::request('PUT', $this->url($path), ['Content-Type' => 'application/json'], ['global_unique_id' => $gtin]);
        Logger::apiResponse('woo', 'PUT', $this->safeUrl($path), $res->status, "global_unique_id={$gtin}");
        if ($res->isSuccess()) {
            return null;
        }
        $msg = json_decode($res->body, true)['message'] ?? '';
        return 'HTTP ' . $res->status . ($msg !== '' ? ': ' . strip_tags((string) $msg) : '');
    }

    /** @return array{status:int, body:string} diagnostyka produktu */
    /**
     * Jednostki wagi i wymiarów ustawione w sklepie (Ustawienia → Produkty).
     * Waga i wymiary produktów w API są podawane właśnie w nich - bez tego 240 g
     * wyglądało w CRM jak 240 kg. Null = sklep nie udostępnił ustawienia (brak uprawnień klucza).
     * @return array{weight:?string,dimension:?string}
     */
    public function storeUnits(): array
    {
        static $cache = [];
        $key = $this->config['base_url'] ?? '';
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $get = function (string $opt): ?string {
            $res = Http::request('GET', $this->url("/wp-json/wc/v3/settings/products/{$opt}"));
            Logger::apiResponse('woo', 'GET', $this->safeUrl("/wp-json/wc/v3/settings/products/{$opt}"), $res->status, 'units');
            $v = $res->isSuccess() ? ($res->json()['value'] ?? null) : null;
            return is_string($v) && $v !== '' ? strtolower($v) : null;
        };
        return $cache[$key] = ['weight' => $get('woocommerce_weight_unit'), 'dimension' => $get('woocommerce_dimension_unit')];
    }

    /**
     * Adres pliku z biblioteki mediów WordPressa (pola własne typu „obrazek” trzymają tylko ID).
     * /wp/v2/media jest publiczne dla opublikowanych załączników - bez kluczy Woo w adresie.
     */
    public function mediaUrl(int $mediaId): ?string
    {
        $path = "/wp-json/wp/v2/media/{$mediaId}";
        $res = Http::request('GET', $this->safeUrl($path) . '?_fields=source_url');
        Logger::apiResponse('woo', 'GET', $this->safeUrl($path), $res->status, 'media');
        $url = $res->isSuccess() ? ($res->json()['source_url'] ?? null) : null;
        return is_string($url) && $url !== '' ? $url : null;
    }

    /** Pojedynczy produkt (GET /products/{id}). Null, gdy sklep go nie zwróci. */
    public function fetchProduct(int $id): ?array
    {
        $res = Http::request('GET', $this->url("/wp-json/wc/v3/products/{$id}"));
        Logger::apiResponse('woo', 'GET', $this->safeUrl("/wp-json/wc/v3/products/{$id}"), $res->status, 'product');
        return $res->isSuccess() ? $res->json() : null;
    }

    /** Pojedyncze zamówienie (GET /orders/{id}). Null, gdy sklep go nie zwróci. */
    public function fetchOrder(int $id): ?array
    {
        $res = Http::request('GET', $this->url("/wp-json/wc/v3/orders/{$id}"));
        Logger::apiResponse('woo', 'GET', $this->safeUrl("/wp-json/wc/v3/orders/{$id}"), $res->status, 'order');
        return $res->isSuccess() ? $res->json() : null;
    }

    /** Pojedynczy wariant (GET /products/{parent}/variations/{id}). */
    public function fetchVariation(int $parentId, int $variationId): ?array
    {
        $res = Http::request('GET', $this->url("/wp-json/wc/v3/products/{$parentId}/variations/{$variationId}"));
        Logger::apiResponse('woo', 'GET', $this->safeUrl("/wp-json/wc/v3/products/{$parentId}/variations/{$variationId}"), $res->status, 'variation');
        return $res->isSuccess() ? $res->json() : null;
    }

    public function debugFetchProducts(): array
    {
        $res = Http::request('GET', $this->url('/wp-json/wc/v3/products', ['per_page' => 1]));
        return ['status' => $res->status, 'body' => mb_substr($res->body, 0, 6000)];
    }

    public function updateOrderStatus(int $wooOrderId, string $wooStatus): bool
    {
        $res = Http::request(
            'PUT',
            $this->url("/wp-json/wc/v3/orders/{$wooOrderId}"),
            ['Content-Type' => 'application/json'],
            ['status' => $wooStatus]
        );
        Logger::apiResponse('woo', 'PUT', $this->safeUrl("/wp-json/wc/v3/orders/{$wooOrderId}"), $res->status, "set status={$wooStatus}");
        return $res->isSuccess();
    }

    /** Tworzy zamówienie w sklepie (np. z Allegro). @return ?int woo_order_id */
    public function createOrder(array $lineItems, array $billing, string $customerNote = ''): ?int
    {
        $body = [
            'status'        => 'processing',
            'set_paid'      => true,
            'billing'       => $billing,
            'line_items'    => $lineItems,
            'customer_note' => $customerNote,
        ];
        $res = Http::request('POST', $this->url('/wp-json/wc/v3/orders'), ['Content-Type' => 'application/json'], $body);
        Logger::apiResponse('woo', 'POST', $this->safeUrl('/wp-json/wc/v3/orders'), $res->status, 'create order');
        if (!$res->isSuccess()) {
            return null;
        }
        return (int) ($res->json()['id'] ?? 0) ?: null;
    }

    /**
     * Produkt / wariant o danym SKU w tym sklepie.
     * @return array{product_id:int,variation_id:int}|null
     */
    public function findBySku(string $sku): ?array
    {
        if (trim($sku) === '') {
            return null;
        }
        $res = Http::request('GET', $this->url('/wp-json/wc/v3/products', ['sku' => $sku, 'per_page' => 1]));
        Logger::apiResponse('woo', 'GET', $this->safeUrl('/wp-json/wc/v3/products'), $res->status, "sku={$sku}");
        $p = $res->isSuccess() ? ($res->json()[0] ?? null) : null;
        if (!is_array($p) || empty($p['id'])) {
            return null;
        }
        // SKU wariantu - Woo zwraca wariant z parent_id.
        return !empty($p['parent_id'])
            ? ['product_id' => (int) $p['parent_id'], 'variation_id' => (int) $p['id']]
            : ['product_id' => (int) $p['id'], 'variation_id' => 0];
    }

    /**
     * Tworzy zamówienie z pełnego body REST (billing, shipping, line_items, shipping_lines, meta_data...).
     * @return array{ok:bool,id:int,message:string}
     */
    public function createOrderRaw(array $body): array
    {
        $res = Http::request('POST', $this->url('/wp-json/wc/v3/orders'), ['Content-Type' => 'application/json'], $body);
        Logger::apiResponse('woo', 'POST', $this->safeUrl('/wp-json/wc/v3/orders'), $res->status, 'forward order');
        $j = $res->json();
        if ($res->isSuccess() && !empty($j['id'])) {
            return ['ok' => true, 'id' => (int) $j['id'], 'message' => 'Utworzono zamówienie #' . ($j['number'] ?? $j['id']) . '.'];
        }
        return ['ok' => false, 'id' => 0, 'message' => (string) ($j['message'] ?? ('kod ' . $res->status))];
    }

    /**
     * Zwrot do zamówienia. POST /orders/{id}/refunds
     * Status 0 = brak odpowiedzi (wynik nieznany).
     * @return array{ok:bool,status:int,id:string,message:string}
     */
    public function createRefund(int $wooOrderId, array $body): array
    {
        $path = "/wp-json/wc/v3/orders/{$wooOrderId}/refunds";
        $res = Http::request('POST', $this->url($path), ['Content-Type' => 'application/json'], $body);
        Logger::apiResponse('woo', 'POST', $this->safeUrl($path), $res->status, 'refund');
        $j = $res->json();
        if ($res->isSuccess() && !empty($j['id'])) {
            return ['ok' => true, 'status' => $res->status, 'id' => (string) $j['id'], 'message' => ''];
        }
        return ['ok' => false, 'status' => $res->status, 'id' => '', 'message' => (string) ($j['message'] ?? ('Sklep odpowiedział kodem ' . $res->status . '.'))];
    }

    /** @return ?int aktualny stan produktu w sklepie */
    public function getStock(int $wooProductId): ?int
    {
        $res = Http::request('GET', $this->url("/wp-json/wc/v3/products/{$wooProductId}"));
        Logger::apiResponse('woo', 'GET', $this->safeUrl("/wp-json/wc/v3/products/{$wooProductId}"), $res->status, "stock product={$wooProductId}");
        if (!$res->isSuccess()) {
            return null;
        }
        $sq = $res->json()['stock_quantity'] ?? null;
        return $sq !== null ? (int) $sq : null;
    }
}
