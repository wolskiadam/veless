<?php
declare(strict_types=1);

namespace PasePlugin\Erli;

use Pase\Support\Http;

/**
 * Klient ERLI Shop API (https://erli.pl/svc/shop-api/doc/).
 *
 * Połączenie: klucz API sklepu (panel sprzedawcy ERLI → Ustawienia → Integracje / API), wysyłany jako
 * „Authorization: Bearer <klucz>”. Kwoty w API są w groszach (liczby całkowite).
 *
 *  - GET   /me                         dane sklepu (test połączenia)
 *  - POST  /orders/_search             zamówienia (sortowane po dacie zmiany, filtr updated >=, kursor `cursor`)
 *  - PATCH /orders/{id}                numer przesyłki (deliveryTracking: status, vendor, trackingNumber)
 *  - PATCH /orders/{id}/status         status sprzedawcy (sellerStatus, np. sent)
 *  - POST  /products/_search           produkty sklepu ze stanami (kursor po externalId)
 *  - PATCH /products/batch-update      stany produktów (po externalId), wynik osobno dla każdego produktu
 */
final class ErliClient
{
    public const API_URLS = [
        'production' => 'https://erli.pl/svc/shop-api',
        'sandbox'    => 'https://sandbox.erli.dev/svc/shop-api',
    ];
    public const BATCH_LIMIT = 50;

    /** @var callable(string,string,array,?string):array{0:int,1:string} */
    private $transport;

    /**
     * @param array<string,mixed> $config api_key, environment (z konta integracji)
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
        return $this->apiKey() !== '';
    }

    private function apiKey(): string
    {
        return trim((string) ($this->config['api_key'] ?? ''));
    }

    public function baseUrl(): string
    {
        return self::API_URLS[($this->config['environment'] ?? 'production') === 'sandbox' ? 'sandbox' : 'production'];
    }

    /** @return mixed zdekodowana odpowiedź JSON (null przy pustej) */
    public function call(string $method, string $path, ?array $body = null, array $headers = []): mixed
    {
        if (!$this->configured()) {
            throw new \RuntimeException('Wpisz klucz API sklepu ERLI w ustawieniach integracji.');
        }
        $json = $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        [$status, $raw] = ($this->transport)($method, $this->baseUrl() . $path, $headers + [
            'Authorization' => 'Bearer ' . $this->apiKey(),
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ], $json);
        if ((int) $status === 0) {
            throw new \RuntimeException('Brak połączenia z ERLI (sprawdź internet na serwerze).');
        }
        $res = trim((string) $raw) !== '' ? json_decode((string) $raw, true) : null;
        if ((int) $status >= 400) {
            throw new ErliException(self::errorText(is_array($res) ? $res : [], (int) $status, (string) $raw), (int) $status);
        }
        return $res;
    }

    /** Czytelny komunikat błędu ERLI (polski, jeśli API go podało). */
    public static function errorText(array $res, int $status, string $raw = ''): string
    {
        $msg = trim((string) ($res['polishMessage'] ?? $res['message'] ?? $res['error']['polishMessage'] ?? $res['error']['message'] ?? ''));
        if ($msg === '' && $raw !== '' && $res === []) {
            $msg = mb_substr(trim(strip_tags($raw)), 0, 200);
        }
        $hint = match ($status) {
            401, 403 => ' Sprawdź klucz API w ustawieniach integracji.',
            429      => ' ERLI ogranicza liczbę zapytań - kolejna próba przy następnej synchronizacji.',
            default  => '',
        };
        return 'ERLI (HTTP ' . $status . ')' . ($msg !== '' ? ': ' . $msg : '') . '.' . $hint;
    }

    /** @return array<string,mixed> id, name, active, company{nip,name} */
    public function shop(): array
    {
        $res = $this->call('GET', '/me');
        return is_array($res) ? $res : [];
    }

    /**
     * Zamówienia zmienione od $updatedFrom (włącznie), od najstarszej zmiany. Kolejna strona: $after = `cursor` ostatniego.
     * @return array<int,array<string,mixed>>
     */
    public function searchOrders(int $updatedFrom, string $after = '', int $limit = 100): array
    {
        $pagination = ['sortField' => 'updated', 'order' => 'ASC', 'limit' => $limit];
        if ($after !== '') {
            $pagination['after'] = $after;
        }
        $res = $this->call('POST', '/orders/_search', [
            'pagination' => $pagination,
            'filter'     => ['field' => 'updated', 'operator' => '>=', 'value' => gmdate('Y-m-d\TH:i:s.000\Z', $updatedFrom)],
        ]);
        return array_values(array_filter(is_array($res) ? $res : [], 'is_array'));
    }

    /** @return array<string,mixed> */
    public function order(string $id): array
    {
        $res = $this->call('GET', '/orders/' . rawurlencode($id));
        return is_array($res) ? $res : [];
    }

    /**
     * Produkty sklepu (externalId, sku, ean, name, stock, frozen), stronami po externalId.
     * @return array<int,array<string,mixed>>
     */
    public function searchProducts(string $after = '', int $limit = 100): array
    {
        $pagination = ['sortField' => 'externalId', 'order' => 'ASC', 'limit' => $limit];
        if ($after !== '') {
            $pagination['after'] = $after;
        }
        $res = $this->call('POST', '/products/_search', [
            'pagination' => $pagination,
            'fields'     => ['name', 'sku', 'ean', 'stock', 'status', 'frozen'],
        ]);
        return array_values(array_filter(is_array($res) ? $res : [], 'is_array'));
    }

    /**
     * Stany produktów: [externalId => ilość]. Zwraca błędy po externalId (pusta tablica = wszystkie przyjęte).
     * @param array<string,int> $stocks
     * @return array<string,string>
     */
    public function updateStocks(array $stocks): array
    {
        $errors = [];
        foreach (array_chunk($stocks, self::BATCH_LIMIT, true) as $chunk) {
            $body = [];
            foreach ($chunk as $externalId => $qty) {
                $body[] = ['externalId' => (string) $externalId, 'stock' => max(0, (int) $qty)];
            }
            $res = $this->call('PATCH', '/products/batch-update', $body);
            foreach (is_array($res) ? $res : [] as $r) {
                if (!is_array($r) || !isset($r['externalId'])) {
                    continue;
                }
                $st = (int) ($r['status'] ?? 200);
                if ($st >= 400 || !empty($r['error'])) {
                    $e = is_array($r['error'] ?? null) ? $r['error'] : [];
                    $errors[(string) $r['externalId']] = 'HTTP ' . $st . ': ' . trim((string) ($e['polishMessage'] ?? $e['message'] ?? $e['name'] ?? 'odrzucone'));
                }
            }
        }
        return $errors;
    }

    /** Numer przesyłki do zamówienia i status sprzedawcy „wysłane”. */
    public function markShipped(string $orderId, string $trackingNumber, string $vendor): void
    {
        $this->call('PATCH', '/orders/' . rawurlencode($orderId), [
            'deliveryTracking' => ['status' => 'sent', 'vendor' => $vendor, 'trackingNumber' => $trackingNumber],
        ]);
        $this->call('PATCH', '/orders/' . rawurlencode($orderId) . '/status', ['status' => 'sent']);
    }
}
