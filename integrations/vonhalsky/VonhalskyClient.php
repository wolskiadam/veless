<?php
declare(strict_types=1);

namespace PasePlugin\Vonhalsky;

use Pase\Support\Http;

/**
 * Klient API InPost vonHalsky (marketplace InPost, https://inpsa-api-portal.inpost-group.com/).
 *
 * Logowanie: OAuth2 „authorization code” z PKCE (S256). Client ID i Client Secret daje InPost (API jest tylko dla
 * znanych integratorów); token zgody wymienia się z Basic auth (client_id:client_secret). Access token żyje ok. 9 minut,
 * refresh token ok. 30 dni - odnawia go rdzeń (Pase\Services\Vonhalsky).
 *
 * Każda operacja sprzedawcy działa w kontekście organizacji (sklepu, poziom 2) - jej id jest w ścieżce.
 *
 *  - GET   /v1/organizations                                 organizacje konta (wybór sklepu po połączeniu)
 *  - GET   /v1/organizations/{org}/orders                    zamówienia (updatedAtGte, sort, limit ≤ 30, offset)
 *  - GET   /v1/organizations/{org}/orders/{id}               jedno zamówienie
 *  - POST  /v1/organizations/{org}/orders/{id}/accept        przyjęcie zamówienia (asynchronicznie, commandId)
 *  - POST  /v1/organizations/{org}/orders/{id}/refuse        odrzucenie zamówienia
 *  - GET   /v1/organizations/{org}/offers                    oferty (externalId, product.sku, stock, price)
 *  - PATCH /v1/organizations/{org}/offers/stocks             stany wielu ofert naraz [{offerId, stock{quantity, unit}}]
 *  - PATCH /v1/organizations/{org}/offers/prices             ceny wielu ofert naraz [{offerId, price{amount, currency}}]
 *  - GET   /v1/categories, /v1/categories/{id}/attributes     drzewo kategorii i parametry kategorii (wystawianie)
 *  - GET   /v1/organizations/{org}/offers/hint               podpowiedź produktu po EAN (kategoria, parametry, GPSR)
 *  - POST  /v1/organizations/{org}/offers, PATCH .../{id}    nowa oferta / zmiana oferty; POST .../{id}/close|reopen
 *  - GET   /v1/organizations/{org}/returns, POST .../{id}/accept|reject                    zwroty
 *  - GET   /v1/organizations/{org}/claims, POST .../orders/{o}/claims/{c}/refund|partial-refund|reject   reklamacje
 *  - POST  /v1/organizations/{org}/orders/{id}/refund        zwrot pieniędzy za zamówienie (całość albo kwota)
 *
 * Etykiet, numerów przesyłek, wiadomości od kupujących ani faktur API vonHalsky nie ma.
 */
final class VonhalskyClient
{
    public const API_URLS = [
        'production' => 'https://api.inpost-group.com/inpsa',
        'stage'      => 'https://stage-api.inpost-group.com/inpsa',
    ];
    public const AUTH_URLS = [
        'production' => 'https://account.inpost-group.com/oauth2',
        'stage'      => 'https://stage-account.inpost-group.com/oauth2',
    ];
    public const SCOPES = 'api:categories:read api:offers:read api:offers:write api:orders:read api:orders:write';
    /** Największa strona listy zamówień w API. */
    public const ORDERS_PAGE_MAX = 30;
    public const BATCH_LIMIT = 50;

    /** @var callable(string,string,array,?string):array{0:int,1:string} */
    private $transport;

    /**
     * @param array<string,mixed> $config client_id, client_secret, environment (z konta integracji)
     * @param ?string $accessToken token sprzedawcy (bez niego działają tylko strona zgody i wymiana kodu)
     * @param ?callable $transport atrapa HTTP w testach: fn($method, $url, $headers, $body) => [status, body]
     */
    public function __construct(private readonly array $config, private readonly ?string $accessToken = null, ?callable $transport = null)
    {
        $this->transport = $transport ?? static function (string $method, string $url, array $headers, ?string $body): array {
            $res = Http::request($method, $url, $headers, $body, 30);
            return [$res->status, $res->body];
        };
    }

    public function configured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    private function clientId(): string
    {
        return trim((string) ($this->config['client_id'] ?? ''));
    }

    private function clientSecret(): string
    {
        return trim((string) ($this->config['client_secret'] ?? ''));
    }

    private function env(): string
    {
        return ($this->config['environment'] ?? 'production') === 'stage' ? 'stage' : 'production';
    }

    public function baseUrl(): string
    {
        return self::API_URLS[$this->env()];
    }

    public function authUrl(): string
    {
        return self::AUTH_URLS[$this->env()];
    }

    // ---------------------------------------------------------------- OAuth2 + PKCE

    /** Para PKCE: [code_verifier, code_challenge (S256)]. */
    public static function pkcePair(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        return [$verifier, $challenge];
    }

    /** Adres strony zgody InPost. */
    public function authorizeUrl(string $state, string $codeChallenge, string $redirectUri): string
    {
        if (!$this->configured()) {
            throw new \RuntimeException('Wpisz Client ID i Client Secret od InPost w ustawieniach integracji i zapisz.');
        }
        return $this->authUrl() . '/authorize?' . http_build_query([
            'response_type'         => 'code',
            'client_id'             => $this->clientId(),
            'redirect_uri'          => $redirectUri,
            'scope'                 => self::SCOPES,
            'state'                 => $state,
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Kod zgody -> tokeny. @return array{access_token:string,refresh_token?:string,expires_in?:int} */
    public function exchangeCode(string $code, string $codeVerifier, string $redirectUri): array
    {
        return $this->token([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'code_verifier' => $codeVerifier,
        ]);
    }

    /** Odnowienie tokenu. @return array{access_token:string,refresh_token?:string,expires_in?:int} */
    public function refreshToken(string $refreshToken): array
    {
        return $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    private function token(array $form): array
    {
        if (!$this->configured()) {
            throw new \RuntimeException('Wpisz Client ID i Client Secret od InPost w ustawieniach integracji.');
        }
        [$status, $raw] = ($this->transport)('POST', $this->authUrl() . '/token', [
            'Authorization' => 'Basic ' . base64_encode($this->clientId() . ':' . $this->clientSecret()),
            'Content-Type'  => 'application/x-www-form-urlencoded',
            'Accept'        => 'application/json',
        ], http_build_query($form, '', '&'));
        if ((int) $status === 0) {
            throw new \RuntimeException('Brak połączenia z InPost (sprawdź internet na serwerze).');
        }
        $res = json_decode((string) $raw, true);
        $res = is_array($res) ? $res : [];
        if ((int) $status >= 400 || empty($res['access_token'])) {
            $msg = trim((string) ($res['error_description'] ?? $res['error'] ?? ''));
            throw new VonhalskyException('InPost logowanie (HTTP ' . (int) $status . ')' . ($msg !== '' ? ': ' . $msg : '')
                . '. Sprawdź Client ID, Client Secret i środowisko, potem połącz konto jeszcze raz.', (int) $status);
        }
        return $res;
    }

    // ---------------------------------------------------------------- wywołania API

    /** @return mixed zdekodowana odpowiedź JSON (null przy pustej) */
    public function call(string $method, string $path, ?array $query = null, ?array $body = null): mixed
    {
        $token = trim((string) $this->accessToken);
        if ($token === '') {
            throw new \RuntimeException('Konto InPost vonHalsky nie jest połączone - kliknij „Połącz z InPost vonHalsky” na stronie integracji.');
        }
        $url = $this->baseUrl() . $path;
        if ($query) {
            // Tablice jako powtórzony parametr (style=form, explode=true): sort=a&sort=b.
            $parts = [];
            foreach ($query as $k => $v) {
                foreach ((array) $v as $one) {
                    $parts[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $one);
                }
            }
            $url .= '?' . implode('&', $parts);
        }
        $json = $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        [$status, $raw] = ($this->transport)($method, $url, [
            'Authorization'   => 'Bearer ' . $token,
            'Accept'          => 'application/json',
            'Accept-Language' => 'pl',
            'Content-Type'    => 'application/json',
        ], $json);
        if ((int) $status === 0) {
            throw new \RuntimeException('Brak połączenia z InPost vonHalsky (sprawdź internet na serwerze).');
        }
        $res = trim((string) $raw) !== '' ? json_decode((string) $raw, true) : null;
        if ((int) $status >= 400) {
            throw new VonhalskyException(self::errorText(is_array($res) ? $res : [], (int) $status, (string) $raw), (int) $status);
        }
        return $res;
    }

    /** Czytelny komunikat błędu (ErrorResponse: errorCode, errorMessage, details[{field, detail}]). */
    public static function errorText(array $res, int $status, string $raw = ''): string
    {
        $msg = trim((string) ($res['errorMessage'] ?? $res['message'] ?? $res['detail'] ?? $res['title'] ?? ''));
        $details = [];
        foreach ((array) ($res['details'] ?? []) as $d) {
            if (is_array($d) && trim((string) ($d['detail'] ?? '')) !== '') {
                $details[] = (trim((string) ($d['field'] ?? '')) !== '' ? $d['field'] . ': ' : '') . trim((string) $d['detail']);
            }
        }
        if ($details !== []) {
            $msg .= ($msg !== '' ? ' (' : '') . implode('; ', array_slice($details, 0, 3)) . ($msg !== '' ? ')' : '');
        }
        if ($msg === '' && $raw !== '' && $res === []) {
            $msg = mb_substr(trim(strip_tags($raw)), 0, 200);
        }
        $hint = match ($status) {
            401      => ' Token wygasł albo jest nieważny - połącz konto jeszcze raz.',
            403      => ' Brak uprawnień - sprawdź, czy konto ma dostęp do tej organizacji (sklepu).',
            429      => ' InPost ogranicza liczbę zapytań - kolejna próba przy następnej synchronizacji.',
            default  => '',
        };
        return 'InPost vonHalsky (HTTP ' . $status . ')' . ($msg !== '' ? ': ' . $msg : '') . '.' . $hint;
    }

    /** @return array<int,array<string,mixed>> organizacje: id, name, type, status, parent */
    public function organizations(): array
    {
        $res = $this->call('GET', '/v1/organizations');
        if (is_array($res) && isset($res['data']) && is_array($res['data'])) {
            $res = $res['data'];
        }
        return is_array($res) ? array_values(array_filter($res, 'is_array')) : [];
    }

    /**
     * Opłacone zamówienia zmienione od $updatedFrom (włącznie), od najstarszej zmiany.
     * @return array<int,array<string,mixed>>
     */
    public function orders(string $org, int $updatedFrom, int $offset = 0, int $limit = self::ORDERS_PAGE_MAX): array
    {
        $res = $this->call('GET', '/v1/organizations/' . rawurlencode($org) . '/orders', [
            'updatedAtGte' => gmdate('Y-m-d\TH:i:s\Z', $updatedFrom),
            'sort'         => 'updatedAt',
            'limit'        => max(1, min(self::ORDERS_PAGE_MAX, $limit)),
            'offset'       => max(0, $offset),
        ]);
        return is_array($res['data'] ?? null) ? array_values(array_filter($res['data'], 'is_array')) : [];
    }

    /** @return array<string,mixed> */
    public function order(string $org, string $orderId): array
    {
        $res = $this->call('GET', '/v1/organizations/' . rawurlencode($org) . '/orders/' . rawurlencode($orderId));
        return is_array($res) ? $res : [];
    }

    /** Przyjęcie zamówienia. @return array<string,mixed> commandId, status */
    public function acceptOrder(string $org, string $orderId): array
    {
        $res = $this->call('POST', '/v1/organizations/' . rawurlencode($org) . '/orders/' . rawurlencode($orderId) . '/accept');
        return is_array($res) ? $res : [];
    }

    /** Odrzucenie zamówienia. @return array<string,mixed> commandId, status */
    public function refuseOrder(string $org, string $orderId): array
    {
        $res = $this->call('POST', '/v1/organizations/' . rawurlencode($org) . '/orders/' . rawurlencode($orderId) . '/refuse');
        return is_array($res) ? $res : [];
    }

    /**
     * Strona ofert sprzedawcy (pole `offer` z OfferDetails, metadane pod `_metadata`).
     * @return array{0:array<int,array<string,mixed>>,1:int} [oferty, łączna liczba]
     */
    public function offers(string $org, int $offset = 0, int $limit = 50): array
    {
        $res = $this->call('GET', '/v1/organizations/' . rawurlencode($org) . '/offers', [
            'sort'   => 'createdAt',
            'limit'  => max(1, $limit),
            'offset' => max(0, $offset),
        ]);
        $out = [];
        foreach ((array) ($res['data'] ?? []) as $row) {
            $offer = is_array($row['offer'] ?? null) ? $row['offer'] : (is_array($row) && isset($row['id']) ? $row : null);
            if ($offer !== null) {
                // Metadane (błędy walidacji, powody odrzucenia) - do pokazania przy ofercie.
                $offer['_metadata'] = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
                $out[] = $offer;
            }
        }
        return [$out, (int) ($res['page']['total'] ?? 0)];
    }

    /**
     * Stany ofert. @param array<string,array{quantity:int,unit?:string}> $stocks offerId => stan
     * @return array<int,array<string,mixed>> polecenia (commandId, offerId, status)
     */
    public function updateStocks(string $org, array $stocks): array
    {
        $body = [];
        foreach ($stocks as $offerId => $s) {
            $body[] = ['offerId' => (string) $offerId, 'stock' => ['quantity' => max(0, (int) $s['quantity']), 'unit' => (string) ($s['unit'] ?? 'UNIT') ?: 'UNIT']];
        }
        $res = $this->call('PATCH', '/v1/organizations/' . rawurlencode($org) . '/offers/stocks', null, $body);
        return is_array($res) ? array_values(array_filter($res, 'is_array')) : [];
    }

    /**
     * Ceny brutto ofert. @param array<string,array{amount:float,currency?:string}> $prices offerId => cena
     * @return array<int,array<string,mixed>> polecenia (commandId, offerId, status)
     */
    public function updatePrices(string $org, array $prices): array
    {
        $body = [];
        foreach ($prices as $offerId => $p) {
            $body[] = ['offerId' => (string) $offerId, 'price' => ['amount' => round((float) $p['amount'], 2), 'currency' => (string) ($p['currency'] ?? 'PLN') ?: 'PLN']];
        }
        $res = $this->call('PATCH', '/v1/organizations/' . rawurlencode($org) . '/offers/prices', null, $body);
        return is_array($res) ? array_values(array_filter($res, 'is_array')) : [];
    }

    // ---------------------------------------------------------------- kategorie i oferty (wystawianie)

    private function org(string $org): string
    {
        return '/v1/organizations/' . rawurlencode($org);
    }

    /** Drzewo kategorii od korzenia (z dziećmi do $depth poziomów). @return array<int,array<string,mixed>> */
    public function categories(int $depth = 10, ?string $parentId = null): array
    {
        $q = ['depth' => max(1, $depth)];
        if ($parentId !== null && $parentId !== '') {
            $q['categoryId'] = $parentId;
        }
        $res = $this->call('GET', '/v1/categories', $q);
        if (is_array($res) && isset($res['data']) && is_array($res['data'])) {
            $res = $res['data'];
        }
        return is_array($res) ? array_values(array_filter($res, 'is_array')) : [];
    }

    /** Parametry kategorii (AttributeDefinition: id, type, lang, expectedValue, name, dictionary.options, unitOfMeasureDetails). */
    public function categoryAttributes(string $categoryId): array
    {
        $res = $this->call('GET', '/v1/categories/' . rawurlencode($categoryId) . '/attributes');
        if (is_array($res) && isset($res['data']) && is_array($res['data'])) {
            $res = $res['data'];
        }
        return is_array($res) ? array_values(array_filter($res, 'is_array')) : [];
    }

    /** Podpowiedzi produktu po EAN (albo nazwie): [{product{categoryId, attributes, brand...}, gpsr[]}]. */
    public function hints(string $org, string $ean = '', string $name = ''): array
    {
        $q = array_filter(['ean' => $ean, 'name' => $name], static fn($v) => $v !== '');
        if ($q === []) {
            return [];
        }
        $res = $this->call('GET', $this->org($org) . '/offers/hint', $q);
        return is_array($res['data'] ?? null) ? array_values(array_filter($res['data'], 'is_array')) : [];
    }

    /** Nowa oferta (OfferProposal). @return array{commandId?:string,offerId?:string,externalId?:string} */
    public function createOffer(string $org, array $offer): array
    {
        $res = $this->call('POST', $this->org($org) . '/offers', null, $offer);
        return is_array($res) ? $res : [];
    }

    /** Zmiana oferty (OfferPatch). @return array<string,mixed> OfferDetails */
    public function patchOffer(string $org, string $offerId, array $patch): array
    {
        $res = $this->call('PATCH', $this->org($org) . '/offers/' . rawurlencode($offerId), null, $patch);
        return is_array($res) ? $res : [];
    }

    /** Oferta z metadanymi (błędy walidacji, powody odrzucenia). @return array{offer?:array,metadata?:array} */
    public function offer(string $org, string $offerId): array
    {
        $res = $this->call('GET', $this->org($org) . '/offers/' . rawurlencode($offerId));
        return is_array($res) ? $res : [];
    }

    public function closeOffer(string $org, string $offerId): array
    {
        $res = $this->call('POST', $this->org($org) . '/offers/' . rawurlencode($offerId) . '/close');
        return is_array($res) ? $res : [];
    }

    public function reopenOffer(string $org, string $offerId): array
    {
        $res = $this->call('POST', $this->org($org) . '/offers/' . rawurlencode($offerId) . '/reopen');
        return is_array($res) ? $res : [];
    }

    /** Status polecenia na ofercie (PENDING / SUCCESS / FAILURE z błędami). */
    public function offerCommand(string $org, string $commandId): array
    {
        $res = $this->call('GET', $this->org($org) . '/offers/commands/' . rawurlencode($commandId));
        return is_array($res) ? $res : [];
    }

    // ---------------------------------------------------------------- zwroty, reklamacje, zwrot pieniędzy

    /** @return array{0:array<int,array<string,mixed>>,1:int} [zwroty, łączna liczba] */
    public function returns(string $org, int $offset = 0, int $limit = 50): array
    {
        $res = $this->call('GET', $this->org($org) . '/returns', ['limit' => max(1, $limit), 'offset' => max(0, $offset)]);
        return [is_array($res['data'] ?? null) ? array_values(array_filter($res['data'], 'is_array')) : [], (int) ($res['page']['total'] ?? 0)];
    }

    public function acceptReturn(string $org, string $returnId): array
    {
        $res = $this->call('POST', $this->org($org) . '/returns/' . rawurlencode($returnId) . '/accept');
        return is_array($res) ? $res : [];
    }

    public function rejectReturn(string $org, string $returnId): array
    {
        $res = $this->call('POST', $this->org($org) . '/returns/' . rawurlencode($returnId) . '/reject');
        return is_array($res) ? $res : [];
    }

    /** @return array{0:array<int,array<string,mixed>>,1:int} [reklamacje (skrót), łączna liczba] */
    public function claims(string $org, int $offset = 0, int $limit = 50, ?int $submittedFrom = null): array
    {
        $q = ['limit' => max(1, $limit), 'offset' => max(0, $offset), 'sort' => '-submission_date'];
        if ($submittedFrom !== null) {
            $q['submissionDateFrom'] = gmdate('Y-m-d\TH:i:s\Z', $submittedFrom);
        }
        $res = $this->call('GET', $this->org($org) . '/claims', $q);
        $list = $res['items'] ?? $res['data'] ?? [];
        return [is_array($list) ? array_values(array_filter($list, 'is_array')) : [], (int) ($res['page']['total'] ?? 0)];
    }

    /** Pełna reklamacja (opis, żądanie, pozycje, załączniki). */
    public function claim(string $org, string $orderId, string $claimId): array
    {
        $res = $this->call('GET', $this->org($org) . '/orders/' . rawurlencode($orderId) . '/claims/' . rawurlencode($claimId));
        return is_array($res) ? $res : [];
    }

    /** Rozstrzygnięcie reklamacji: refund (całość), partial-refund (procent z żądania klienta), reject. */
    public function resolveClaim(string $org, string $orderId, string $claimId, string $decision, string $comment = ''): array
    {
        $path = ['refund' => 'refund', 'partial' => 'partial-refund', 'reject' => 'reject'][$decision] ?? null;
        if ($path === null) {
            throw new \InvalidArgumentException('Nieznana decyzja reklamacji: ' . $decision);
        }
        $res = $this->call('POST', $this->org($org) . '/orders/' . rawurlencode($orderId) . '/claims/' . rawurlencode($claimId) . '/' . $path,
            null, trim($comment) !== '' ? ['description' => mb_substr(trim($comment), 0, 1000)] : null);
        return is_array($res) ? $res : [];
    }

    /** Zwrot pieniędzy za zamówienie: bez kwoty - całość. @return array{refundAmount?:array,status?:string,description?:string} */
    public function refundOrder(string $org, string $orderId, ?float $amount = null, string $currency = 'PLN'): array
    {
        $body = $amount !== null ? ['amount' => ['amount' => round($amount, 2), 'currency' => $currency ?: 'PLN']] : null;
        $res = $this->call('POST', $this->org($org) . '/orders/' . rawurlencode($orderId) . '/refund', null, $body);
        return is_array($res) ? $res : [];
    }
}
