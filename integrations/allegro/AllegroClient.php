<?php
declare(strict_types=1);

namespace PasePlugin\Allegro;

use Pase\Repository\IntegrationRepository;
use Pase\Support\Http;
use Pase\Support\HttpResponse;
use Pase\Support\Logger;

/**
 * Klient API Allegro (OAuth 2.0 + oferty) — logika należąca do WTYCZKI.
 *
 * Allegro różni się od Woo/BLPaczka: ma OAuth (Authorization Code Grant), a tokeny
 * trzymamy w tabeli `integrations` (nie w configu konta). Config (client_id/secret,
 * redirect_uri, env) pochodzi z .env. Klient korzysta ze wspólnych narzędzi rdzenia
 * (Http, Logger) oraz IntegrationRepository do zapisu/odczytu tokenów.
 */
final class AllegroClient
{
    private const PLATFORM = 'allegro';

    /**
     * Czy w tym żądaniu próbowaliśmy już odświeżyć token. Bez tego seria zapytań
     * na starym tokenie (np. stronicowanie ofert) waliłaby w /auth/oauth/token
     * raz za razem, a przy wygasłym refresh_tokenie robiłaby to w pętli.
     */
    private bool $refreshAttempted = false;
    private ?string $login = null;

    public function __construct(
        private readonly array $config,                 // client_id, client_secret, redirect_uri, env
        private readonly IntegrationRepository $integrations
    ) {}

    private function authBaseUrl(): string
    {
        return ($this->config['env'] ?? 'sandbox') === 'production'
            ? 'https://allegro.pl'
            : 'https://allegro.pl.allegrosandbox.pl';
    }

    private function apiBaseUrl(): string
    {
        return ($this->config['env'] ?? 'sandbox') === 'production'
            ? 'https://api.allegro.pl'
            : 'https://api.allegro.pl.allegrosandbox.pl';
    }

    /** Krok 1 OAuth: URL zgody sprzedawcy. */
    public function buildAuthorizationUrl(string $state): string
    {
        $query = http_build_query([
            'response_type' => 'code',
            'client_id'     => $this->config['client_id'] ?? '',
            'redirect_uri'  => $this->config['redirect_uri'] ?? '',
            'state'         => $state,
        ]);
        return $this->authBaseUrl() . '/auth/oauth/authorize?' . $query;
    }

    /** Krok 2 OAuth: wymiana code -> token + zapis do bazy. */
    public function exchangeCodeForToken(string $code): bool
    {
        // Endpoint /auth/oauth/token żyje na tej samej domenie co /auth/oauth/authorize
        // (allegro.pl), NIE pod api.allegro.pl - inaczej Allegro zwraca 404 NotFoundException
        // "Feature unavailable. Contact the application author."
        $res = Http::request('POST', $this->authBaseUrl() . '/auth/oauth/token', $this->basicAuthHeaders(), http_build_query([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => $this->config['redirect_uri'] ?? '',
        ]));
        Logger::apiResponse('allegro', 'POST', '/auth/oauth/token', $res->status, 'token exchange');
        if (!$res->isSuccess()) {
            Logger::error('Allegro: wymiana code->token nieudana', ['status' => $res->status, 'body' => mb_substr($res->body, 0, 500)]);
            return false;
        }
        return $this->persistTokenResponse($res->json());
    }

    /** Odświeżenie access_token (cron refresh_tokens.php). */
    public function refreshAccessToken(string $refreshToken): bool
    {
        // Patrz komentarz w exchangeCodeForToken() - ten sam endpoint, ta sama domena.
        $res = Http::request('POST', $this->authBaseUrl() . '/auth/oauth/token', $this->basicAuthHeaders(), http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
            'redirect_uri'  => $this->config['redirect_uri'] ?? '',
        ]));
        Logger::apiResponse('allegro', 'POST', '/auth/oauth/token', $res->status, 'token refresh');
        if (!$res->isSuccess()) {
            Logger::error('Allegro: odświeżenie tokenu nieudane', ['status' => $res->status, 'body' => mb_substr($res->body, 0, 500)]);
            return false;
        }
        return $this->persistTokenResponse($res->json());
    }

    /** PATCH stanu magazynowego oferty (sync stanów Woo -> Allegro). */
    public function updateOfferQuantity(string $offerId, int $quantity): bool
    {
        $token = $this->accessToken();
        if ($token === null) {
            Logger::error('Allegro: brak access_token - nie mogę zaktualizować stanu');
            return false;
        }

        $res = $this->sendOfferQuantity($offerId, $quantity, $token);

        // To samo co przy odczycie: wygasły token odświeżamy w locie i ponawiamy.
        // Tu jest to ważniejsze niż przy listach - nieudany PATCH oznacza rozjechany
        // stan magazynowy na ofercie, o czym nikt się nie dowie poza logiem.
        if ($res->status === 401 && $this->tryRefreshAccessToken()) {
            $fresh = $this->accessToken();
            if ($fresh !== null && $fresh !== '' && $fresh !== $token) {
                $res = $this->sendOfferQuantity($offerId, $quantity, $fresh);
            }
        }

        Logger::apiResponse('allegro', 'PATCH', "/sale/product-offers/{$offerId}", $res->status, "qty={$quantity}");
        if (!$res->isSuccess()) {
            Logger::error('Allegro: aktualizacja stanu oferty nieudana', ['offer' => $offerId, 'status' => $res->status, 'body' => mb_substr($res->body, 0, 500)]);
        }
        return $res->isSuccess();
    }

    // ============================================================
    //  Operacje na ofertach (Zarządzanie ofertami → Operacje)
    // ============================================================

    /**
     * Autoryzowane żądanie JSON (PATCH/PUT/POST/GET) z odświeżeniem tokenu przy 401.
     * @param array<string,mixed>|null $body
     */
    private function authedJson(string $method, string $path, ?array $body = null): ?HttpResponse
    {
        $token = $this->accessToken();
        if ($token === null || $token === '') {
            return null;
        }
        $send = fn(string $t) => Http::request($method, $this->apiBaseUrl() . $path, [
            'Authorization' => "Bearer {$t}",
            'Accept'        => 'application/vnd.allegro.public.v1+json',
            'Content-Type'  => 'application/vnd.allegro.public.v1+json',
            // userMessage w błędach (m.in. validation oferty) po polsku, jak w panelu Allegro.
            'Accept-Language' => 'pl-PL',
        ] + $this->uaHeader(), $body, 30);
        $res = $send($token);
        if ($res->status === 401 && $this->tryRefreshAccessToken()) {
            $fresh = $this->accessToken();
            if ($fresh !== null && $fresh !== '' && $fresh !== $token) {
                $res = $send($fresh);
            }
        }
        return $res;
    }

    /** Pierwszy czytelny błąd z odpowiedzi Allegro. */
    private function firstError(HttpResponse $res): string
    {
        $e = $res->json()['errors'][0] ?? null;
        $msg = is_array($e) ? trim((string) ($e['userMessage'] ?? $e['message'] ?? '')) : '';
        return $msg !== '' ? $msg : "Allegro odpowiedziało kodem {$res->status}.";
    }

    /**
     * Częściowa zmiana oferty. PATCH /sale/product-offers/{id}
     * @param array<string,mixed> $changes np. ['name' => ...], ['sellingMode' => ['price' => ...]]
     * @return array{ok:bool,message:string}
     */
    public function patchOffer(string $offerId, array $changes): array
    {
        $res = $this->authedJson('PATCH', '/sale/product-offers/' . rawurlencode($offerId), $changes);
        if ($res === null) {
            return ['ok' => false, 'message' => 'Brak tokenu Allegro — połącz konto.'];
        }
        Logger::apiResponse('allegro', 'PATCH', "/sale/product-offers/{$offerId}", $res->status, implode(',', array_keys($changes)));
        return $res->isSuccess() ? ['ok' => true, 'message' => ''] : ['ok' => false, 'message' => $this->firstError($res)];
    }

    /** Pełne dane oferty. GET /sale/product-offers/{id} */
    public function productOffer(string $offerId): ?array
    {
        $res = $this->authedJson('GET', '/sale/product-offers/' . rawurlencode($offerId));
        return $res !== null && $res->isSuccess() ? $res->json() : null;
    }

    /**
     * Zakończenie / aktywacja ofert jednym poleceniem. PUT /sale/offer-publication-commands/{uuid}
     * @param string[] $offerIds
     * @return array{ok:bool,message:string,command_id:?string}
     */
    public function publicationCommand(array $offerIds, string $action): array
    {
        $action = $action === 'ACTIVATE' ? 'ACTIVATE' : 'END';
        $uuid = sprintf('%04x%04x-%04x-4%03x-%04x-%04x%04x%04x', random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xfff), random_int(0x8000, 0xbfff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff));
        $res = $this->authedJson('PUT', "/sale/offer-publication-commands/{$uuid}", [
            'publication'   => ['action' => $action],
            'offerCriteria' => [['type' => 'CONTAINS_OFFERS', 'offers' => array_map(static fn($id) => ['id' => (string) $id], array_values($offerIds))]],
        ]);
        if ($res === null) {
            return ['ok' => false, 'message' => 'Brak tokenu Allegro — połącz konto.', 'command_id' => null];
        }
        Logger::apiResponse('allegro', 'PUT', '/sale/offer-publication-commands', $res->status, $action . ' x' . count($offerIds));
        return $res->isSuccess() ? ['ok' => true, 'message' => '', 'command_id' => $uuid]
                                 : ['ok' => false, 'message' => $this->firstError($res), 'command_id' => null];
    }

    /**
     * Wynik polecenia publikacji (po ofercie). GET /sale/offer-publication-commands/{id}/tasks
     * @return array<int,array{offer:string,status:string,message:string}>
     */
    public function publicationCommandTasks(string $commandId): array
    {
        $res = $this->authedJson('GET', "/sale/offer-publication-commands/{$commandId}/tasks");
        if ($res === null || !$res->isSuccess()) {
            return [];
        }
        $out = [];
        foreach ($res->json()['tasks'] ?? [] as $t) {
            $out[] = ['offer' => (string) ($t['offer']['id'] ?? ''), 'status' => (string) ($t['status'] ?? ''),
                      'message' => (string) ($t['errors'][0]['userMessage'] ?? $t['errors'][0]['message'] ?? $t['message'] ?? '')];
        }
        return $out;
    }

    // ============================================================
    //  GPSR: osoby odpowiedzialne (Marketplace → Osoby odpowiedzialne)
    // ============================================================

    /**
     * Osoby odpowiedzialne sprzedawcy. GET /sale/responsible-persons (stronicowane po 100).
     * @return array{ok:bool,persons:array<int,array<string,mixed>>,message:string}
     */
    public function responsiblePersons(): array
    {
        $all = [];
        for ($offset = 0, $guard = 0; $guard < 50; $guard++, $offset += 100) {
            $res = $this->authedJson('GET', '/sale/responsible-persons?limit=100&offset=' . $offset);
            if ($res === null) {
                return ['ok' => false, 'persons' => [], 'message' => 'Brak tokenu Allegro — połącz konto.'];
            }
            Logger::apiResponse('allegro', 'GET', '/sale/responsible-persons', $res->status, 'osoby odpowiedzialne');
            if (!$res->isSuccess()) {
                return ['ok' => $all !== [], 'persons' => $all, 'message' => $this->firstError($res)];
            }
            $j = $res->json();
            $batch = $j['responsiblePersons'] ?? [];
            $all = array_merge($all, $batch);
            if (count($batch) < 100 || count($all) >= (int) ($j['totalCount'] ?? 0)) {
                break;
            }
        }
        return ['ok' => true, 'persons' => $all, 'message' => ''];
    }

    /**
     * Dodanie (id=null) albo zmiana osoby odpowiedzialnej. POST / PUT /sale/responsible-persons[/{id}]
     * @param array<string,mixed> $body {name, personalData:{name, address:{...}, contact:{...}}}
     * @return array{ok:bool,message:string,id:?string}
     */
    public function saveResponsiblePerson(?string $id, array $body): array
    {
        $res = $id === null
            ? $this->authedJson('POST', '/sale/responsible-persons', $body)
            : $this->authedJson('PUT', '/sale/responsible-persons/' . rawurlencode($id), ['id' => $id] + $body);
        if ($res === null) {
            return ['ok' => false, 'message' => 'Brak tokenu Allegro — połącz konto.', 'id' => null];
        }
        Logger::apiResponse('allegro', $id === null ? 'POST' : 'PUT', '/sale/responsible-persons', $res->status, 'zapis osoby odpowiedzialnej');
        if (!$res->isSuccess()) {
            $errs = array_map(static fn($e) => (string) ($e['userMessage'] ?? $e['message'] ?? ''), $res->json()['errors'] ?? []);
            $errs = array_values(array_filter($errs));
            return ['ok' => false, 'message' => $errs ? implode(' ', $errs) : $this->firstError($res), 'id' => null];
        }
        return ['ok' => true, 'message' => '', 'id' => (string) ($res->json()['id'] ?? $id)];
    }

    /**
     * Producenci odpowiedzialni (GPSR). GET /sale/responsible-producers (po 100 na stronę).
     * @return array{ok:bool,producers:array<int,array<string,mixed>>,message:string}
     */
    public function responsibleProducers(): array
    {
        $all = [];
        for ($offset = 0, $guard = 0; $guard < 50; $guard++, $offset += 100) {
            $res = $this->authedJson('GET', '/sale/responsible-producers?limit=100&offset=' . $offset);
            if ($res === null) {
                return ['ok' => false, 'producers' => [], 'message' => 'Brak tokenu Allegro — połącz konto.'];
            }
            Logger::apiResponse('allegro', 'GET', '/sale/responsible-producers', $res->status, 'producenci odpowiedzialni');
            if (!$res->isSuccess()) {
                return ['ok' => $all !== [], 'producers' => $all, 'message' => $this->firstError($res)];
            }
            $j = $res->json();
            $batch = $j['responsibleProducers'] ?? [];
            $all = array_merge($all, $batch);
            if (count($batch) < 100 || count($all) >= (int) ($j['totalCount'] ?? 0)) {
                break;
            }
        }
        return ['ok' => true, 'producers' => $all, 'message' => ''];
    }

    /**
     * Dodanie (id=null) albo zmiana producenta. POST / PUT /sale/responsible-producers[/{id}]
     * @param array<string,mixed> $body {name, producerData:{tradeName, address:{...}, contact:{...}}}
     * @return array{ok:bool,message:string,id:?string}
     */
    public function saveResponsibleProducer(?string $id, array $body): array
    {
        $res = $id === null
            ? $this->authedJson('POST', '/sale/responsible-producers', $body)
            : $this->authedJson('PUT', '/sale/responsible-producers/' . rawurlencode($id), ['id' => $id] + $body);
        if ($res === null) {
            return ['ok' => false, 'message' => 'Brak tokenu Allegro — połącz konto.', 'id' => null];
        }
        Logger::apiResponse('allegro', $id === null ? 'POST' : 'PUT', '/sale/responsible-producers', $res->status, 'zapis producenta');
        if (!$res->isSuccess()) {
            $errs = array_map(static fn($e) => (string) ($e['userMessage'] ?? $e['message'] ?? ''), $res->json()['errors'] ?? []);
            $errs = array_values(array_filter($errs));
            return ['ok' => false, 'message' => $errs ? implode(' ', $errs) : $this->firstError($res), 'id' => null];
        }
        return ['ok' => true, 'message' => '', 'id' => (string) ($res->json()['id'] ?? $id)];
    }

    /** Szczegóły cennika dostaw (metody i stawki). GET /sale/shipping-rates/{id} */
    public function shippingRate(string $id): ?array
    {
        $res = $this->authedJson('GET', '/sale/shipping-rates/' . rawurlencode($id));
        if ($res === null) {
            return null;
        }
        Logger::apiResponse('allegro', 'GET', '/sale/shipping-rates/{id}', $res->status, 'cennik dostaw');
        return $res->isSuccess() ? $res->json() : null;
    }

    /** Metody dostawy dostępne w cennikach (z ograniczeniami stawek). GET /sale/delivery-methods */
    public function deliveryMethods(): array
    {
        $res = $this->authedJson('GET', '/sale/delivery-methods');
        if ($res === null) {
            return [];
        }
        Logger::apiResponse('allegro', 'GET', '/sale/delivery-methods', $res->status, 'metody dostawy');
        return $res->isSuccess() ? ($res->json()['deliveryMethods'] ?? []) : [];
    }

    /**
     * Nowy cennik (id=null, POST) albo zmiana istniejącego (PUT /sale/shipping-rates/{id}).
     * @param array<string,mixed> $body {name, rates:[{deliveryMethod:{id}, maxQuantityPerPackage, firstItemRate, nextItemRate, …}]}
     * @return array{ok:bool,message:string,id:?string}
     */
    public function saveShippingRate(?string $id, array $body): array
    {
        $res = $id === null
            ? $this->authedJson('POST', '/sale/shipping-rates', $body)
            : $this->authedJson('PUT', '/sale/shipping-rates/' . rawurlencode($id), ['id' => $id] + $body);
        if ($res === null) {
            return ['ok' => false, 'message' => 'Brak tokenu Allegro — połącz konto.', 'id' => null];
        }
        Logger::apiResponse('allegro', $id === null ? 'POST' : 'PUT', '/sale/shipping-rates', $res->status, 'zapis cennika dostaw');
        if (!$res->isSuccess()) {
            $errs = [];
            foreach ($res->json()['errors'] ?? [] as $e) {
                $msg = trim((string) ($e['userMessage'] ?? $e['message'] ?? ''));
                if ($msg !== '') {
                    $errs[] = $msg . (!empty($e['path']) ? ' (' . $e['path'] . ')' : '');
                }
            }
            return ['ok' => false, 'message' => $errs ? implode(' ', array_unique($errs)) : $this->firstError($res), 'id' => null];
        }
        return ['ok' => true, 'message' => '', 'id' => (string) ($res->json()['id'] ?? $id)];
    }

    /** Test połączenia: GET /me. @return array{0:bool,1:string} */
    public function checkConnection(): array
    {
        $token = $this->accessToken();
        if ($token === null || $token === '') {
            return [false, 'Brak tokenu Allegro — najpierw połącz konto (autoryzacja OAuth).'];
        }
        $res = Http::request('GET', $this->apiBaseUrl() . '/me', [
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/vnd.allegro.public.v1+json',
        ] + $this->uaHeader());
        Logger::apiResponse('allegro', 'GET', '/me', $res->status, 'test połączenia');
        if ($res->status === 401) {
            return [false, 'Token wygasł lub jest nieprawidłowy — połącz konto ponownie.'];
        }
        if (!$res->isSuccess()) {
            return [false, "Allegro odpowiedziało kodem {$res->status}."];
        }
        $login = $res->json()['login'] ?? '';
        return [true, 'Połączono z Allegro' . ($login !== '' ? " (konto: {$login})" : '') . '.'];
    }

    // ============================================================
    //  Oferty (Zarządzanie ofertami / Wystawianie)
    // ============================================================

    /**
     * Lista ofert sprzedawcy. GET /sale/offers
     * @param array<string,string|int> $filters np. ['publication.status' => 'ACTIVE', 'limit' => 50, 'offset' => 0]
     * @return array{ok:bool,offers:array<int,array<string,mixed>>,total:int,message:string}
     */
    public function listOffers(array $filters = []): array
    {
        $res = $this->authedGet('/sale/offers', $filters + ['limit' => 50, 'offset' => 0]);
        if ($res === null) {
            return ['ok' => false, 'offers' => [], 'total' => 0, 'message' => 'Brak tokenu Allegro — połącz konto.'];
        }
        Logger::apiResponse('allegro', 'GET', '/sale/offers', $res->status, 'lista ofert');
        if (!$res->isSuccess()) {
            return ['ok' => false, 'offers' => [], 'total' => 0, 'message' => $this->authErrorMessage($res)];
        }
        $json = $res->json();
        return ['ok' => true, 'offers' => $json['offers'] ?? [], 'total' => (int) ($json['totalCount'] ?? count($json['offers'] ?? [])), 'message' => ''];
    }

    /**
     * GET /sale/offers z gotowym query stringiem - potrzebne, gdy parametr powtarza się
     * (np. offer.id=1&offer.id=2), czego http_build_query w listOffers() nie umie.
     * @return array<int,array<string,mixed>> oferty (pusta lista przy błędzie)
     */
    public function listOffersRaw(string $query): array
    {
        $token = $this->accessToken();
        if ($token === null || $token === '') {
            return [];
        }
        $res = $this->sendAuthedGet($this->apiBaseUrl() . '/sale/offers?' . $query, $token);
        Logger::apiResponse('allegro', 'GET', '/sale/offers', $res->status, 'zdjęcia ofert');
        return $res->isSuccess() ? ($res->json()['offers'] ?? []) : [];
    }

    /**
     * Lista przewoźników obsługiwanych przez śledzenie Allegro (GET /order/carriers).
     * @return array<int,array{id:string,name:string}> pusta przy błędzie
     */
    public function carriers(): array
    {
        $res = $this->authedGet('/order/carriers');
        if ($res === null || !$res->isSuccess()) {
            return [];
        }
        $out = [];
        foreach ($res->json()['carriers'] ?? [] as $c) {
            if (!empty($c['id'])) {
                $out[] = ['id' => (string) $c['id'], 'name' => (string) ($c['name'] ?? $c['id'])];
            }
        }
        return $out;
    }

    /**
     * Historia statusów przesyłek u przewoźnika (GET /order/carriers/{carrierId}/tracking).
     * Allegro przyjmuje do 20 numerów w jednym zapytaniu (parametr waybill powtarzany).
     * @param string[] $waybills
     * @return array{ok:bool,status:int,waybills:array<int,array<string,mixed>>,message:string}
     */
    public function carrierTracking(string $carrierId, array $waybills): array
    {
        $waybills = array_slice(array_values(array_unique(array_filter(array_map('trim', $waybills)))), 0, 20);
        if ($waybills === []) {
            return ['ok' => true, 'status' => 200, 'waybills' => [], 'message' => ''];
        }
        $token = $this->accessToken();
        if ($token === null || $token === '') {
            return ['ok' => false, 'status' => 0, 'waybills' => [], 'message' => 'Konto Allegro nie jest połączone.'];
        }
        $q = implode('&', array_map(static fn($w) => 'waybill=' . rawurlencode($w), $waybills));
        $url = $this->apiBaseUrl() . '/order/carriers/' . rawurlencode($carrierId) . '/tracking?' . $q;
        $res = $this->sendAuthedGet($url, $token);
        if ($res->status === 401 && $this->tryRefreshAccessToken()) {
            $fresh = $this->accessToken();
            if ($fresh !== null && $fresh !== '' && $fresh !== $token) {
                $res = $this->sendAuthedGet($url, $fresh);
            }
        }
        Logger::apiResponse('allegro', 'GET', "/order/carriers/{$carrierId}/tracking", $res->status, count($waybills) . ' przesyłek');
        if (!$res->isSuccess()) {
            $err = $res->json()['errors'][0] ?? [];
            return ['ok' => false, 'status' => $res->status, 'waybills' => [],
                    'message' => (string) ($err['userMessage'] ?? $err['message'] ?? $this->authErrorMessage($res))];
        }
        return ['ok' => true, 'status' => $res->status, 'waybills' => $res->json()['waybills'] ?? [], 'message' => ''];
    }

    // ============================================================
    //  Dostęp dla innych wtyczek korzystających z tego samego połączenia OAuth
    //  (np. „Wysyłam z Allegro" w integrations/allegrowysylka). Dzięki temu nie
    //  duplikują logowania ani odświeżania tokenów.
    // ============================================================

    /** Bazowy URL REST API (produkcja / sandbox) dla bieżącego środowiska. */
    public function apiBase(): string
    {
        return $this->apiBaseUrl();
    }

    /** Aktualny access token Allegro (null = konto niepołączone). */
    public function bearerToken(): ?string
    {
        $t = $this->accessToken();
        return $t !== null && $t !== '' ? $t : null;
    }

    /** Odświeża token po 401 (ten sam mechanizm co przy zapytaniach panelu). Zwraca nowy token albo null. */
    public function refreshBearerToken(): ?string
    {
        return $this->tryRefreshAccessToken() ? $this->bearerToken() : null;
    }

    /** Nagłówek User-Agent wymagany przez Allegro. @return array<string,string> */
    public function userAgentHeaders(): array
    {
        return $this->uaHeader();
    }

    // ============================================================
    //  Zamówienia (Order Events API + Checkout Forms)
    // ============================================================

    /**
     * Zdarzenia zamówień - GET /order/events. To rekomendowany przez Allegro sposób
     * wykrywania nowych/zmienionych zamówień: kursor po id zdarzenia (parametr `from`),
     * bez potrzeby rejestrowania publicznego webhooka ani weryfikacji podpisów.
     * Bez `from` zwraca zdarzenia z całego dostępnego okna retencji Allegro.
     *
     * `status` to kod HTTP odpowiedzi (0 = nie wysłaliśmy zapytania, bo brak tokenu).
     * Wołający potrzebuje go, żeby odróżnić „konto nieczynne" (401) od „kursor wskazuje
     * na zdarzenie, którego Allegro już nie ma" (4xx) - to drugie trzeba naprawić
     * skasowaniem kursora, inaczej pobieranie stoi w miejscu w nieskończoność.
     *
     * @return array{ok:bool,events:array<int,array<string,mixed>>,message:string,status:int}
     */
    public function orderEvents(?string $from = null, int $limit = 100): array
    {
        $query = ['limit' => $limit];
        if ($from !== null && $from !== '') {
            $query['from'] = $from;
        }
        $res = $this->authedGet('/order/events', $query);
        if ($res === null) {
            return ['ok' => false, 'events' => [], 'message' => 'Brak tokenu Allegro — połącz konto.', 'status' => 0];
        }
        Logger::apiResponse('allegro', 'GET', '/order/events', $res->status, 'zdarzenia zamówień');
        if (!$res->isSuccess()) {
            return [
                'ok'      => false,
                'events'  => [],
                'message' => "Allegro odpowiedziało kodem {$res->status}." . $this->errorDetail($res),
                'status'  => $res->status,
            ];
        }
        return ['ok' => true, 'events' => $res->json()['events'] ?? [], 'message' => '', 'status' => $res->status];
    }

    /** Czytelny fragment błędu z odpowiedzi Allegro (do komunikatu w panelu/logu). */
    private function errorDetail(HttpResponse $res): string
    {
        $json = $res->json();
        $first = $json['errors'][0] ?? null;
        if (!is_array($first)) {
            return '';
        }
        $text = trim((string) ($first['userMessage'] ?? $first['message'] ?? ''));
        return $text === '' ? '' : ' ' . $text;
    }

    /** Pełne dane zamówienia po ID. GET /order/checkout-forms/{id} */
    public function checkoutForm(string $id): ?array
    {
        $res = $this->authedGet("/order/checkout-forms/{$id}");
        if ($res === null || !$res->isSuccess()) {
            Logger::apiResponse('allegro', 'GET', "/order/checkout-forms/{$id}", $res?->status ?? 0, 'pobranie zamówienia');
            return null;
        }
        return $res->json();
    }

    /**
     * Lista zamówień do importu historycznego. GET /order/checkout-forms
     * @param array<string,string|int> $filters np. 'updatedAt.gte', 'limit', 'offset'
     * @return array{ok:bool,checkoutForms:array<int,array<string,mixed>>,total:int,message:string}
     */
    public function listCheckoutForms(array $filters = []): array
    {
        $res = $this->authedGet('/order/checkout-forms', $filters + ['limit' => 100, 'offset' => 0]);
        if ($res === null) {
            return ['ok' => false, 'checkoutForms' => [], 'total' => 0, 'message' => 'Brak tokenu Allegro — połącz konto.'];
        }
        Logger::apiResponse('allegro', 'GET', '/order/checkout-forms', $res->status, 'lista zamówień');
        if (!$res->isSuccess()) {
            return ['ok' => false, 'checkoutForms' => [], 'total' => 0, 'message' => "Allegro odpowiedziało kodem {$res->status}."];
        }
        $json = $res->json();
        return [
            'ok' => true,
            'checkoutForms' => $json['checkoutForms'] ?? [],
            'total' => (int) ($json['totalCount'] ?? count($json['checkoutForms'] ?? [])),
            'message' => '',
        ];
    }

    /**
     * Zmienia status realizacji zamówienia (widoczny dla kupującego na Allegro).
     * PUT /order/checkout-forms/{id}/fulfillment
     * Allegro nie ma odpowiednika "dowolnego statusu" jak Woo - akceptuje tylko
     * konkretne wartości fulfillment.status (patrz Domain\OrderStatus::toAllegro()).
     */
    public function updateFulfillmentStatus(string $checkoutFormId, string $status): bool
    {
        $token = $this->accessToken();
        if ($token === null) {
            Logger::error('Allegro: brak access_token - nie mogę zmienić statusu zamówienia');
            return false;
        }
        $res = Http::request('PUT', $this->apiBaseUrl() . "/order/checkout-forms/{$checkoutFormId}/fulfillment", [
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/vnd.allegro.public.v1+json',
            'Content-Type'  => 'application/vnd.allegro.public.v1+json',
        ] + $this->uaHeader(), ['status' => $status]);
        Logger::apiResponse('allegro', 'PUT', "/order/checkout-forms/{$checkoutFormId}/fulfillment", $res->status, "status={$status}");
        if (!$res->isSuccess()) {
            Logger::error('Allegro: zmiana statusu zamówienia nieudana', ['order' => $checkoutFormId, 'status_wanted' => $status, 'http_status' => $res->status, 'body' => mb_substr($res->body, 0, 500)]);
        }
        return $res->isSuccess();
    }

    /**
     * Tworzy szkic oferty (product-offer). POST /sale/product-offers
     * Zwraca offerId po sukcesie. @param array<string,mixed> $payload pełne body wg API Allegro.
     * @return array{ok:bool,offer_id:?string,message:string}
     */
    public function createOffer(array $payload): array
    {
        $res = $this->authedJson('POST', '/sale/product-offers', $payload);
        if ($res === null) {
            return ['ok' => false, 'offer_id' => null, 'message' => 'Brak tokenu Allegro — połącz konto.'];
        }
        Logger::apiResponse('allegro', 'POST', '/sale/product-offers', $res->status, 'tworzenie oferty');
        if (!$res->isSuccess()) {
            Logger::error('Allegro: tworzenie oferty nieudane', ['status' => $res->status, 'body' => mb_substr($res->body, 0, 800)]);
            // Wszystkie braki naraz (Allegro zwraca listę błędów walidacji), bez powtórzeń.
            $errs = [];
            foreach ($res->json()['errors'] ?? [] as $e) {
                $msg = is_array($e) ? trim((string) ($e['userMessage'] ?? $e['message'] ?? '')) : '';
                if ($msg !== '') {
                    $errs[$msg] = true;
                }
            }
            return ['ok' => false, 'offer_id' => null, 'message' => $errs ? implode(' ', array_keys($errs)) : $this->firstError($res)];
        }
        return ['ok' => true, 'offer_id' => (string) ($res->json()['id'] ?? ''), 'message' => 'Utworzono ofertę.'];
    }

    /**
     * Załącznik oferty (np. instrukcja obsługi PDF): POST /sale/offer-attachments tworzy wpis,
     * PUT na upload.allegro.pl wysyła plik. Zwraca id do „attachments" w ofercie.
     * @return array{ok:bool,id:string,message:string}
     */
    public function uploadOfferAttachment(string $type, string $fileName, string $contentType, string $bytes): array
    {
        $res = $this->authedJson('POST', '/sale/offer-attachments', ['type' => $type, 'file' => ['name' => $fileName]]);
        if ($res === null) {
            return ['ok' => false, 'id' => '', 'message' => 'Brak tokenu Allegro — połącz konto.'];
        }
        $id = (string) ($res->json()['id'] ?? '');
        if (!$res->isSuccess() || $id === '') {
            Logger::error('Allegro: tworzenie załącznika nieudane', ['status' => $res->status, 'body' => mb_substr($res->body, 0, 500)]);
            return ['ok' => false, 'id' => '', 'message' => $this->firstError($res)];
        }
        $uploadBase = ($this->config['env'] ?? 'sandbox') === 'production'
            ? 'https://upload.allegro.pl' : 'https://upload.allegro.pl.allegrosandbox.pl';
        $put = fn(string $t) => Http::request('PUT', $uploadBase . '/sale/offer-attachments/' . rawurlencode($id), [
            'Authorization' => "Bearer {$t}",
            'Accept'        => 'application/vnd.allegro.public.v1+json',
            'Content-Type'  => $contentType,
            'Accept-Language' => 'pl-PL',
        ] + $this->uaHeader(), $bytes, 60);
        $up = $put((string) $this->accessToken());
        if (!$up->isSuccess()) {
            Logger::error('Allegro: wysyłka pliku załącznika nieudana', ['status' => $up->status, 'body' => mb_substr($up->body, 0, 500)]);
            return ['ok' => false, 'id' => '', 'message' => $this->firstError($up)];
        }
        return ['ok' => true, 'id' => $id, 'message' => 'Dodano załącznik.'];
    }

    /**
     * Zdjęcie na serwery Allegro (POST upload.allegro.pl/sale/images, plik binarnie) - np. grafiki opisu.
     * Zwraca adres a.allegroimg.com do użycia w images / description oferty.
     * @return array{ok:bool,url:string,message:string}
     */
    public function uploadImage(string $bytes, string $contentType = 'image/jpeg'): array
    {
        $token = $this->accessToken();
        if ($token === null || $token === '') {
            return ['ok' => false, 'url' => '', 'message' => 'Brak tokenu Allegro — połącz konto.'];
        }
        $uploadBase = ($this->config['env'] ?? 'sandbox') === 'production'
            ? 'https://upload.allegro.pl' : 'https://upload.allegro.pl.allegrosandbox.pl';
        $send = fn(string $t) => Http::request('POST', $uploadBase . '/sale/images', [
            'Authorization' => "Bearer {$t}",
            'Accept'        => 'application/vnd.allegro.public.v1+json',
            'Content-Type'  => $contentType,
            'Accept-Language' => 'pl-PL',
        ] + $this->uaHeader(), $bytes, 60);
        $res = $send($token);
        if ($res->status === 401 && $this->tryRefreshAccessToken()) {
            $fresh = $this->accessToken();
            if ($fresh !== null && $fresh !== '' && $fresh !== $token) {
                $res = $send($fresh);
            }
        }
        $url = (string) ($res->json()['location'] ?? '');
        if (!$res->isSuccess() || $url === '') {
            Logger::error('Allegro: wysyłka zdjęcia nieudana', ['status' => $res->status, 'body' => mb_substr($res->body, 0, 500)]);
            return ['ok' => false, 'url' => '', 'message' => $this->firstError($res)];
        }
        return ['ok' => true, 'url' => $url, 'message' => ''];
    }

    // ============================================================
    //  Kategorie i parametry
    // ============================================================

    /**
     * Kategorie Allegro. GET /sale/categories (gdy $parent=null → korzenie).
     * @return array<int,array<string,mixed>>
     */
    public function categories(?string $parent = null): array
    {
        $res = $this->authedGet('/sale/categories', $parent !== null ? ['parent.id' => $parent] : []);
        if ($res === null || !$res->isSuccess()) {
            Logger::apiResponse('allegro', 'GET', '/sale/categories', $res?->status ?? 0, 'kategorie');
            return [];
        }
        return $res->json()['categories'] ?? [];
    }

    /**
     * Parametry kategorii. GET /sale/categories/{id}/parameters
     * @return array<int,array<string,mixed>>
     */
    public function categoryParameters(string $categoryId): array
    {
        $res = $this->authedGet("/sale/categories/{$categoryId}/parameters");
        if ($res === null || !$res->isSuccess()) {
            return [];
        }
        return $res->json()['parameters'] ?? [];
    }

    /** Jedna kategoria (nazwa, rodzic, czy liść). GET /sale/categories/{id} */
    public function category(string $id): ?array
    {
        $res = $this->authedGet('/sale/categories/' . rawurlencode($id));
        return $res !== null && $res->isSuccess() ? $res->json() : null;
    }

    /**
     * Kategorie pasujące do nazwy produktu. GET /sale/matching-categories?name=
     * @return array<int,array<string,mixed>> {id, name, leaf, parent:{id,name,parent:{...}}}
     */
    public function matchingCategories(string $name): array
    {
        $res = $this->authedGet('/sale/matching-categories', ['name' => $name]);
        if ($res === null || !$res->isSuccess()) {
            Logger::apiResponse('allegro', 'GET', '/sale/matching-categories', $res?->status ?? 0, 'dopasowanie kategorii');
            return [];
        }
        return $res->json()['matchingCategories'] ?? [];
    }

    /**
     * Stawki VAT dostępne w kategorii. GET /sale/tax-settings?category.id=
     * @return array<string,mixed> {subjects, rates:[{value,label,countryCode}], exemptions}
     */
    public function taxSettings(string $categoryId): array
    {
        // Bez countryCode Allegro zwraca ustawienia dla wszystkich krajów (PL, CZ, SK, HU, LT).
        $res = $this->authedGet('/sale/tax-settings', ['category.id' => $categoryId]);
        return $res !== null && $res->isSuccess() ? (array) $res->json() : [];
    }

    /**
     * Produkty z Katalogu Allegro: po EAN (mode=GTIN) albo po nazwie. GET /sale/products
     * @return array{ok:bool,products:array<int,array<string,mixed>>,message:string}
     */
    public function searchProducts(string $phrase, ?string $categoryId = null, bool $byGtin = false): array
    {
        $q = ['phrase' => $phrase, 'language' => 'pl-PL'];
        if ($byGtin) {
            $q['mode'] = 'GTIN';
        }
        if ($categoryId !== null && $categoryId !== '') {
            $q['category.id'] = $categoryId;
        }
        $res = $this->authedGet('/sale/products', $q);
        if ($res === null) {
            return ['ok' => false, 'products' => [], 'message' => 'Brak tokenu Allegro — połącz konto.'];
        }
        Logger::apiResponse('allegro', 'GET', '/sale/products', $res->status, 'katalog produktów');
        if (!$res->isSuccess()) {
            return ['ok' => false, 'products' => [], 'message' => $this->firstError($res)];
        }
        return ['ok' => true, 'products' => $res->json()['products'] ?? [], 'message' => ''];
    }

    // ============================================================
    //  Warunki oferty: zwroty, reklamacje, gwarancje
    // ============================================================

    /**
     * Warunki sprzedawcy: return-policies (zwroty), implied-warranties (reklamacje), warranties (gwarancje).
     * GET /after-sales-service-conditions/{kind}
     * @return array{ok:bool,items:array<int,array<string,mixed>>,message:string}
     */
    public function afterSalesConditions(string $kind): array
    {
        $key = ['return-policies' => 'returnPolicies', 'implied-warranties' => 'impliedWarranties', 'warranties' => 'warranties'][$kind] ?? null;
        if ($key === null) {
            return ['ok' => false, 'items' => [], 'message' => 'Nieznany rodzaj warunków.'];
        }
        $res = $this->authedGet('/after-sales-service-conditions/' . $kind, ['limit' => 60]);
        if ($res === null) {
            return ['ok' => false, 'items' => [], 'message' => 'Brak tokenu Allegro — połącz konto.'];
        }
        Logger::apiResponse('allegro', 'GET', '/after-sales-service-conditions/' . $kind, $res->status, 'warunki oferty');
        if (!$res->isSuccess()) {
            return ['ok' => false, 'items' => [], 'message' => $this->firstError($res)];
        }
        return ['ok' => true, 'items' => $res->json()[$key] ?? [], 'message' => ''];
    }

    /**
     * Grupy usług dodatkowych sprzedawcy („Usługi dodatkowe"). GET /sale/offer-additional-services/groups
     * @return array{ok:bool,items:array<int,array<string,mixed>>,message:string}
     */
    public function additionalServiceGroups(): array
    {
        return $this->listOf('/sale/offer-additional-services/groups', 'additionalServicesGroups', 'usługi dodatkowe');
    }

    /**
     * Cenniki hurtowe („Rabat dla klientów biznesowych"). GET /sale/loyalty/wholesale-price-lists
     * @return array{ok:bool,items:array<int,array<string,mixed>>,message:string}
     */
    public function wholesalePriceLists(): array
    {
        return $this->listOf('/sale/loyalty/wholesale-price-lists', 'wholesalePriceLists', 'cenniki hurtowe');
    }

    /**
     * Pakiety wyróżnień (podstawowe, np. „Wyróżnienie", i dodatkowe, np. strona kategorii). GET /sale/offer-promotion-packages
     * @return array{ok:bool,items:array<int,array<string,mixed>>,message:string} pakiety podstawowe
     */
    public function promotionPackages(): array
    {
        return $this->listOf('/sale/offer-promotion-packages', 'basePackages', 'pakiety wyróżnień');
    }

    /**
     * Reguły automatycznych cen sprzedawcy. GET /sale/price-automation/rules
     * @return array{ok:bool,items:array<int,array<string,mixed>>,message:string}
     */
    public function priceAutomationRules(): array
    {
        return $this->listOf('/sale/price-automation/rules', 'rules', 'reguły cenowe');
    }

    /**
     * Podpina regułę automatycznych cen do oferty. PUT /sale/price-automation/offers/{offerId}/rules
     * @param array<string,mixed> $body
     * @return array{ok:bool,message:string}
     */
    public function setOfferPriceRules(string $offerId, array $body): array
    {
        return $this->sendJson('PUT', '/sale/price-automation/offers/' . rawurlencode($offerId) . '/rules', $body, 'reguła cenowa oferty');
    }

    /**
     * Wyróżnienie / promowanie oferty (płatne). POST /sale/offers/{offerId}/promo-options-modification
     * @param array<string,mixed> $body
     * @return array{ok:bool,message:string}
     */
    public function modifyOfferPromotion(string $offerId, array $body): array
    {
        return $this->sendJson('POST', '/sale/offers/' . rawurlencode($offerId) . '/promo-options-modification', $body, 'wyróżnienie oferty');
    }

    /** @return array{ok:bool,message:string} */
    private function sendJson(string $method, string $path, array $body, string $what): array
    {
        $res = $this->authedJson($method, $path, $body);
        if ($res === null) {
            return ['ok' => false, 'message' => 'Brak tokenu Allegro — połącz konto.'];
        }
        Logger::apiResponse('allegro', $method, $path, $res->status, $what);
        return $res->isSuccess() ? ['ok' => true, 'message' => ''] : ['ok' => false, 'message' => $this->firstError($res)];
    }

    /** @return array{ok:bool,items:array<int,array<string,mixed>>,message:string} */
    private function listOf(string $path, string $key, string $what): array
    {
        $res = $this->authedGet($path);
        if ($res === null) {
            return ['ok' => false, 'items' => [], 'message' => 'Brak tokenu Allegro — połącz konto.'];
        }
        Logger::apiResponse('allegro', 'GET', $path, $res->status, $what);
        if (!$res->isSuccess()) {
            return ['ok' => false, 'items' => [], 'message' => $this->firstError($res)];
        }
        return ['ok' => true, 'items' => $res->json()[$key] ?? [], 'message' => ''];
    }

    /**
     * Szacunkowe opłaty i prowizja za ofertę („Podsumowanie"). POST /pricing/offer-fee-preview
     * @param array<string,mixed> $offer body oferty (jak do POST /sale/product-offers)
     * @return array{ok:bool,commissions:array<int,array<string,mixed>>,quotes:array<int,array<string,mixed>>,message:string}
     */
    public function offerFeePreview(array $offer): array
    {
        $res = $this->authedJson('POST', '/pricing/offer-fee-preview', ['offer' => $offer]);
        if ($res === null) {
            return ['ok' => false, 'commissions' => [], 'quotes' => [], 'message' => 'Brak tokenu Allegro — połącz konto.'];
        }
        Logger::apiResponse('allegro', 'POST', '/pricing/offer-fee-preview', $res->status, 'podgląd opłat');
        if (!$res->isSuccess()) {
            return ['ok' => false, 'commissions' => [], 'quotes' => [], 'message' => $this->firstError($res)];
        }
        $j = $res->json();
        return ['ok' => true, 'commissions' => $j['commissions'] ?? [], 'quotes' => $j['quotes'] ?? [], 'message' => ''];
    }

    // ============================================================
    //  Rozliczenia i Allegro Ads
    // ============================================================

    /**
     * Rodzaje operacji w rozliczeniach (np. SUC „Prowizja od sprzedaży"). GET /billing/billing-types
     * @return array{ok:bool,items:array<int,array{id:string,description:string}>,message:string}
     */
    public function billingTypes(): array
    {
        $res = $this->authedJson('GET', '/billing/billing-types');
        if ($res === null) {
            return ['ok' => false, 'items' => [], 'message' => 'Brak tokenu Allegro — połącz konto.'];
        }
        Logger::apiResponse('allegro', 'GET', '/billing/billing-types', $res->status, 'rodzaje opłat');
        if (!$res->isSuccess()) {
            return ['ok' => false, 'items' => [], 'message' => $this->firstError($res)];
        }
        $items = [];
        foreach ((array) $res->json() as $t) {
            if (is_array($t) && isset($t['id'])) {
                $items[] = ['id' => (string) $t['id'], 'description' => (string) ($t['description'] ?? $t['id'])];
            }
        }
        return ['ok' => true, 'items' => $items, 'message' => ''];
    }

    /**
     * Operacje z rozliczeń w okresie, tylko wybranych rodzajów. GET /billing/billing-entries
     * (stronicowane po 100; limit $maxPages chroni przed bardzo długim ładowaniem).
     * @param string[] $typeIds
     * @return array{ok:bool,items:array<int,array<string,mixed>>,message:string,truncated:bool}
     */
    public function billingEntries(\DateTimeInterface $from, \DateTimeInterface $to, array $typeIds, int $maxPages = 30): array
    {
        $base = 'occurredAt.gte=' . rawurlencode($from->format('Y-m-d\TH:i:s.v\Z'))
            . '&occurredAt.lte=' . rawurlencode($to->format('Y-m-d\TH:i:s.v\Z'));
        foreach ($typeIds as $t) {
            $base .= '&type.id=' . rawurlencode((string) $t);
        }
        $items = [];
        for ($page = 0; $page < $maxPages; $page++) {
            $path = '/billing/billing-entries?' . $base . '&limit=100&offset=' . ($page * 100);
            $res = $this->authedJson('GET', $path);
            if ($res === null) {
                return ['ok' => false, 'items' => [], 'message' => 'Brak tokenu Allegro — połącz konto.', 'truncated' => false];
            }
            if (!$res->isSuccess()) {
                Logger::apiResponse('allegro', 'GET', '/billing/billing-entries', $res->status, 'rozliczenia');
                return ['ok' => false, 'items' => $items, 'message' => $this->firstError($res), 'truncated' => false];
            }
            $chunk = $res->json()['billingEntries'] ?? [];
            array_push($items, ...array_values(array_filter((array) $chunk, 'is_array')));
            if (count($chunk) < 100) {
                return ['ok' => true, 'items' => $items, 'message' => '', 'truncated' => false];
            }
        }
        return ['ok' => true, 'items' => $items, 'message' => '', 'truncated' => true];
    }

    /**
     * Jakość sprzedaży z ostatnich 30 dni (poziom, punkty, wyniki w miarach). GET /sale/quality
     * @return array{ok:bool,items:array<int,array<string,mixed>>,message:string}
     */
    public function saleQuality(): array
    {
        $res = $this->authedJson('GET', '/sale/quality');
        if ($res === null) {
            return ['ok' => false, 'items' => [], 'message' => 'Brak tokenu Allegro — połącz konto.'];
        }
        if (!$res->isSuccess()) {
            Logger::apiResponse('allegro', 'GET', '/sale/quality', $res->status, 'jakość sprzedaży');
            return ['ok' => false, 'items' => [], 'message' => $this->firstError($res)];
        }
        return ['ok' => true, 'items' => array_values(array_filter((array) ($res->json()['quality'] ?? []), 'is_array')), 'message' => ''];
    }

    /**
     * Operacje na portfelach płatności (Allegro Finanse / PayU / P24). GET /payments/payment-operations
     * Każda operacja niesie saldo portfela po operacji (wallet.balance).
     * @param array<string,string> $query np. ['wallet.type' => 'AVAILABLE', 'group' => 'INCOME', 'occurredAt.gte' => ...]
     * @return array{ok:bool,items:array<int,array<string,mixed>>,message:string,truncated:bool}
     */
    public function paymentOperations(array $query, int $maxPages = 1): array
    {
        $items = [];
        for ($page = 0; $page < $maxPages; $page++) {
            $path = '/payments/payment-operations?' . http_build_query($query + ['limit' => 50, 'offset' => $page * 50]);
            $res = $this->authedJson('GET', $path);
            if ($res === null) {
                return ['ok' => false, 'items' => [], 'message' => 'Brak tokenu Allegro — połącz konto.', 'truncated' => false];
            }
            if (!$res->isSuccess()) {
                Logger::apiResponse('allegro', 'GET', '/payments/payment-operations', $res->status, 'operacje płatności');
                return ['ok' => false, 'items' => $items, 'message' => $this->firstError($res), 'truncated' => false];
            }
            $chunk = (array) ($res->json()['paymentOperations'] ?? []);
            array_push($items, ...array_values(array_filter($chunk, 'is_array')));
            if (count($chunk) < 50) {
                return ['ok' => true, 'items' => $items, 'message' => '', 'truncated' => false];
            }
        }
        return ['ok' => true, 'items' => $items, 'message' => '', 'truncated' => true];
    }

    /**
     * Sprawdza, czy konto ma dostęp do niepublicznego API Allegro Ads (GET /ads/campaigns, beta).
     * Allegro udostępnia je tylko kontom z listy (whitelist) — zwykle zwraca 401/403.
     * @return array{ok:bool,status:int,message:string,campaigns:array<int,array<string,mixed>>}
     */
    public function adsApiProbe(): array
    {
        $token = $this->bearerToken();
        if ($token === null) {
            return ['ok' => false, 'status' => 0, 'message' => 'Brak tokenu Allegro — połącz konto.', 'campaigns' => []];
        }
        $send = fn(string $t) => Http::request('GET', $this->apiBaseUrl() . '/ads/campaigns', [
            'Authorization' => "Bearer {$t}",
            'Accept'        => 'application/vnd.allegro.beta.v1+json',
        ] + $this->uaHeader(), null, 20);
        $res = $send($token);
        if ($res->status === 401 && ($fresh = $this->refreshBearerToken()) !== null && $fresh !== $token) {
            $res = $send($fresh);
        }
        Logger::apiResponse('allegro', 'GET', '/ads/campaigns', $res->status, 'Allegro Ads API');
        if ($res->isSuccess()) {
            $j = $res->json();
            $list = $j['campaigns'] ?? (array_is_list((array) $j) ? $j : []);
            return ['ok' => true, 'status' => $res->status, 'message' => '', 'campaigns' => array_values(array_filter((array) $list, 'is_array'))];
        }
        return ['ok' => false, 'status' => $res->status, 'message' => $this->firstError($res), 'campaigns' => []];
    }

    // ============================================================
    //  Zwroty klientów i zwroty płatności
    // ============================================================

    /**
     * Zwroty zgłoszone przez kupujących. GET /order/customer-returns (wersja beta API).
     * @param array<string,string|int> $query np. ['createdAt.gte' => ..., 'limit' => 100, 'offset' => 0]
     * @return array{ok:bool,items:array<int,array<string,mixed>>,message:string}
     */
    public function customerReturns(array $query = []): array
    {
        $token = $this->bearerToken();
        if ($token === null) {
            return ['ok' => false, 'items' => [], 'message' => 'Brak tokenu Allegro — połącz konto.'];
        }
        $url = $this->apiBaseUrl() . '/order/customer-returns' . ($query !== [] ? '?' . http_build_query($query) : '');
        $send = fn(string $t) => Http::request('GET', $url, [
            'Authorization'   => "Bearer {$t}",
            'Accept'          => 'application/vnd.allegro.beta.v1+json',
            'Accept-Language' => 'pl-PL',
        ] + $this->uaHeader(), null, 30);
        $res = $send($token);
        if ($res->status === 401 && ($fresh = $this->refreshBearerToken()) !== null && $fresh !== $token) {
            $res = $send($fresh);
        }
        Logger::apiResponse('allegro', 'GET', '/order/customer-returns', $res->status, 'zwroty klientów');
        if (!$res->isSuccess()) {
            return ['ok' => false, 'items' => [], 'message' => $this->firstError($res)];
        }
        return ['ok' => true, 'items' => array_values(array_filter((array) ($res->json()['customerReturns'] ?? []), 'is_array')), 'message' => ''];
    }

    /**
     * Zwrot pieniędzy kupującemu. POST /payments/refunds (wymaga uprawnienia allegro:api:payments:write).
     * Status 0 = brak odpowiedzi (wynik nieznany) — wołający nie może wtedy ponawiać automatycznie.
     * @param array<string,mixed> $body
     * @return array{ok:bool,status:int,id:string,message:string}
     */
    public function refundPayment(array $body): array
    {
        $res = $this->authedJson('POST', '/payments/refunds', $body);
        if ($res === null) {
            return ['ok' => false, 'status' => -1, 'id' => '', 'message' => 'Brak tokenu Allegro — połącz konto.'];
        }
        Logger::apiResponse('allegro', 'POST', '/payments/refunds', $res->status, 'zwrot płatności');
        if ($res->isSuccess()) {
            return ['ok' => true, 'status' => $res->status, 'id' => (string) ($res->json()['id'] ?? ''), 'message' => ''];
        }
        $msg = $this->firstError($res);
        if ($res->status === 403) {
            $msg .= ' Aplikacja Allegro może nie mieć uprawnienia do zwrotów płatności (allegro:api:payments:write).';
        }
        return ['ok' => false, 'status' => $res->status, 'id' => '', 'message' => $msg];
    }

    // ============================================================
    //  Dyskusje i reklamacje (/sale/issues) oraz oceny sprzedaży (/sale/user-ratings)
    // ============================================================

    /**
     * Żądanie z wybraną wersją API (np. beta.v1) i odświeżeniem tokenu przy 401. Null = brak tokenu.
     * @param array<string,string|int> $query
     * @param array<string,mixed>|null $body
     */
    private function versionedRequest(string $method, string $path, array $query, ?array $body, string $mediaType): ?HttpResponse
    {
        $token = $this->bearerToken();
        if ($token === null) {
            return null;
        }
        $url = $this->apiBaseUrl() . $path . ($query !== [] ? '?' . http_build_query($query) : '');
        $headers = ['Accept' => $mediaType, 'Accept-Language' => 'pl-PL'] + ($body !== null ? ['Content-Type' => $mediaType] : []);
        $send = fn(string $t) => Http::request($method, $url, ['Authorization' => "Bearer {$t}"] + $headers + $this->uaHeader(), $body, 30);
        $res = $send($token);
        if ($res->status === 401 && ($fresh = $this->refreshBearerToken()) !== null && $fresh !== $token) {
            $res = $send($fresh);
        }
        return $res;
    }

    /** Dopisek do błędu 403: aplikacji Allegro brakuje uprawnienia. */
    private function scopeHint(HttpResponse $res, string $scope): string
    {
        return $res->status === 403 ? ' Aplikacja Allegro może nie mieć uprawnienia ' . $scope . ' — włącz je w ustawieniach aplikacji na apps.developer.allegro.pl i połącz konto ponownie.' : '';
    }

    /**
     * Dyskusje i reklamacje kupujących. GET /sale/issues (beta).
     * @param array<string,string|int> $query np. ['limit' => 100, 'offset' => 0, 'status' => 'DISPUTE_ONGOING']
     * @return array{ok:bool,items:array<int,array<string,mixed>>,message:string}
     */
    public function issues(array $query = []): array
    {
        $res = $this->versionedRequest('GET', '/sale/issues', $query, null, 'application/vnd.allegro.beta.v1+json');
        if ($res === null) {
            return ['ok' => false, 'items' => [], 'message' => 'Brak tokenu Allegro — połącz konto.'];
        }
        Logger::apiResponse('allegro', 'GET', '/sale/issues', $res->status, 'dyskusje i reklamacje');
        if (!$res->isSuccess()) {
            return ['ok' => false, 'items' => [], 'message' => $this->firstError($res) . $this->scopeHint($res, 'allegro:api:disputes')];
        }
        return ['ok' => true, 'items' => array_values(array_filter((array) ($res->json()['issues'] ?? []), 'is_array')), 'message' => ''];
    }

    /**
     * Jedna dyskusja / reklamacja. GET /sale/issues/{id} (beta).
     * @return array<string,mixed>|null
     */
    public function issue(string $issueId): ?array
    {
        $res = $this->versionedRequest('GET', '/sale/issues/' . rawurlencode($issueId), [], null, 'application/vnd.allegro.beta.v1+json');
        if ($res === null) {
            return null;
        }
        Logger::apiResponse('allegro', 'GET', '/sale/issues/{id}', $res->status, 'dyskusja');
        return $res->isSuccess() ? $res->json() : null;
    }

    /**
     * Wiadomości w dyskusji / reklamacji. GET /sale/issues/{id}/chat (beta), do 300 wiadomości.
     * @return array{ok:bool,items:array<int,array<string,mixed>>,message:string}
     */
    public function issueChat(string $issueId): array
    {
        $all = [];
        for ($offset = 0; $offset < 300; $offset += 100) {
            $res = $this->versionedRequest('GET', '/sale/issues/' . rawurlencode($issueId) . '/chat', ['limit' => 100, 'offset' => $offset], null, 'application/vnd.allegro.beta.v1+json');
            if ($res === null) {
                return ['ok' => false, 'items' => [], 'message' => 'Brak tokenu Allegro — połącz konto.'];
            }
            Logger::apiResponse('allegro', 'GET', '/sale/issues/{id}/chat', $res->status, 'czat dyskusji');
            if (!$res->isSuccess()) {
                return ['ok' => false, 'items' => $all, 'message' => $this->firstError($res) . $this->scopeHint($res, 'allegro:api:disputes')];
            }
            $json = $res->json();
            $batch = array_values(array_filter((array) ($json['chat'] ?? $json['messages'] ?? []), 'is_array'));
            $all = array_merge($all, $batch);
            if (count($batch) < 100) {
                break;
            }
        }
        return ['ok' => true, 'items' => $all, 'message' => ''];
    }

    /**
     * Wiadomość sprzedawcy w dyskusji / reklamacji. POST /sale/issues/{id}/message (beta).
     * Status 0 = brak odpowiedzi (nie wiadomo, czy wiadomość doszła).
     * @return array{ok:bool,status:int,message:string}
     */
    public function sendIssueMessage(string $issueId, string $text, string $type = 'REGULAR'): array
    {
        $res = $this->versionedRequest('POST', '/sale/issues/' . rawurlencode($issueId) . '/message', [], ['text' => $text, 'type' => $type], 'application/vnd.allegro.beta.v1+json');
        if ($res === null) {
            return ['ok' => false, 'status' => -1, 'message' => 'Brak tokenu Allegro — połącz konto.'];
        }
        Logger::apiResponse('allegro', 'POST', '/sale/issues/{id}/message', $res->status, 'odpowiedź w dyskusji');
        if ($res->isSuccess()) {
            return ['ok' => true, 'status' => $res->status, 'message' => ''];
        }
        return ['ok' => false, 'status' => $res->status, 'message' => $this->firstError($res) . $this->scopeHint($res, 'allegro:api:disputes')];
    }

    /**
     * Oceny sprzedaży. GET /sale/user-ratings (beta: comment i answer jako obiekty z polem text).
     * @param array<string,string|int> $query np. ['lastChangedAt.gte' => ..., 'limit' => 100, 'offset' => 0]
     * @return array{ok:bool,items:array<int,array<string,mixed>>,message:string}
     */
    public function userRatings(array $query = []): array
    {
        $res = $this->versionedRequest('GET', '/sale/user-ratings', $query, null, 'application/vnd.allegro.beta.v1+json');
        if ($res === null) {
            return ['ok' => false, 'items' => [], 'message' => 'Brak tokenu Allegro — połącz konto.'];
        }
        Logger::apiResponse('allegro', 'GET', '/sale/user-ratings', $res->status, 'oceny sprzedaży');
        if (!$res->isSuccess()) {
            return ['ok' => false, 'items' => [], 'message' => $this->firstError($res) . $this->scopeHint($res, 'allegro:api:ratings')];
        }
        return ['ok' => true, 'items' => array_values(array_filter((array) ($res->json()['ratings'] ?? []), 'is_array')), 'message' => ''];
    }

    /**
     * Jedna ocena. GET /sale/user-ratings/{id} (beta).
     * @return array<string,mixed>|null
     */
    public function userRating(string $ratingId): ?array
    {
        $res = $this->versionedRequest('GET', '/sale/user-ratings/' . rawurlencode($ratingId), [], null, 'application/vnd.allegro.beta.v1+json');
        if ($res === null) {
            return null;
        }
        Logger::apiResponse('allegro', 'GET', '/sale/user-ratings/{id}', $res->status, 'ocena');
        return $res->isSuccess() ? $res->json() : null;
    }

    /**
     * Publiczna odpowiedź sprzedawcy na ocenę. PUT /sale/user-ratings/{id}/answer
     * @return array{ok:bool,status:int,message:string}
     */
    public function answerUserRating(string $ratingId, string $text): array
    {
        $res = $this->versionedRequest('PUT', '/sale/user-ratings/' . rawurlencode($ratingId) . '/answer', [], ['message' => $text], 'application/vnd.allegro.public.v1+json');
        if ($res === null) {
            return ['ok' => false, 'status' => -1, 'message' => 'Brak tokenu Allegro — połącz konto.'];
        }
        Logger::apiResponse('allegro', 'PUT', '/sale/user-ratings/{id}/answer', $res->status, 'odpowiedź na ocenę');
        if ($res->isSuccess()) {
            return ['ok' => true, 'status' => $res->status, 'message' => ''];
        }
        return ['ok' => false, 'status' => $res->status, 'message' => $this->firstError($res) . $this->scopeHint($res, 'allegro:api:ratings')];
    }

    // ============================================================
    //  Cenniki wysyłek
    // ============================================================

    /**
     * Cenniki dostaw sprzedawcy. GET /sale/shipping-rates
     * @return array<int,array<string,mixed>>
     */
    public function shippingRates(): array
    {
        $res = $this->authedGet('/sale/shipping-rates');
        if ($res === null || !$res->isSuccess()) {
            Logger::apiResponse('allegro', 'GET', '/sale/shipping-rates', $res?->status ?? 0, 'cenniki wysyłek');
            return [];
        }
        return $res->json()['shippingRates'] ?? [];
    }

    // ============================================================
    //  Wiadomości (Messaging)
    // ============================================================

    /*
     * Centrum wiadomości w wersji beta.v1 (od 28.10.2026 jedyna obsługująca „Problemy z zakupem").
     * Różnice względem public.v1: stronicowanie tokenem page.id zamiast offset, wątek ma typ
     * (COMMON / POST_PURCHASE_ISSUE), status (OPEN / CLOSED), uczestników z rolami i zamówienia,
     * a autor wiadomości ma role (BUYER / SELLER) zamiast isInterlocutor.
     */
    private const MESSAGING = 'application/vnd.allegro.beta.v1+json';

    /** nextPage w beta.v1: w wątkach napis, w wiadomościach obiekt {id}. */
    private static function nextPageId(mixed $next): string
    {
        return is_array($next) ? (string) ($next['id'] ?? '') : (is_scalar($next) ? (string) $next : '');
    }

    /**
     * Wątki wiadomości (jedna strona). GET /messaging/threads (beta.v1).
     * @param array<string,string> $filters np. ['type' => 'POST_PURCHASE_ISSUE', 'status' => 'OPEN', 'orderId' => ...]
     * @return array{ok:bool,threads:array<int,array<string,mixed>>,next:string,message:string}
     */
    public function messageThreads(string $pageId = '', array $filters = []): array
    {
        $query = $filters + ($pageId !== '' ? ['page.id' => $pageId] : []);
        $res = $this->versionedRequest('GET', '/messaging/threads', $query, null, self::MESSAGING);
        if ($res === null) {
            return ['ok' => false, 'threads' => [], 'next' => '', 'message' => 'Brak tokenu Allegro — połącz konto.'];
        }
        Logger::apiResponse('allegro', 'GET', '/messaging/threads', $res->status, 'wątki wiadomości');
        if (!$res->isSuccess()) {
            return ['ok' => false, 'threads' => [], 'next' => '', 'message' => $this->firstError($res)];
        }
        $json = $res->json();
        return ['ok' => true, 'threads' => array_values(array_filter((array) ($json['threads'] ?? []), 'is_array')),
                'next' => self::nextPageId($json['nextPage'] ?? null), 'message' => ''];
    }

    /**
     * Jeden wątek (typ, status, uczestnicy, zamówienia). GET /messaging/threads/{id} (beta.v1).
     * @return array<string,mixed>|null
     */
    public function messageThread(string $threadId): ?array
    {
        $res = $this->versionedRequest('GET', '/messaging/threads/' . rawurlencode($threadId), [], null, self::MESSAGING);
        if ($res === null) {
            return null;
        }
        Logger::apiResponse('allegro', 'GET', '/messaging/threads/{id}', $res->status, 'wątek wiadomości');
        return $res->isSuccess() && is_array($res->json()) ? $res->json() : null;
    }

    /**
     * Wiadomości w wątku, od najnowszej. GET /messaging/threads/{id}/messages (beta.v1).
     * @return array<int,array<string,mixed>>
     */
    public function threadMessages(string $threadId, int $limit = 100): array
    {
        // Allegro oddaje maks. 20 wiadomości na stronę - pobieramy kolejne strony (page.id) do $limit.
        $all = [];
        $pageId = '';
        while (count($all) < $limit) {
            $query = ['limit' => min(20, $limit - count($all))] + ($pageId !== '' ? ['page.id' => $pageId] : []);
            $res = $this->versionedRequest('GET', '/messaging/threads/' . rawurlencode($threadId) . '/messages', $query, null, self::MESSAGING);
            if ($res === null || !$res->isSuccess()) {
                if ($res !== null) {
                    Logger::apiResponse('allegro', 'GET', '/messaging/threads/{id}/messages', $res->status, 'wiadomości w wątku');
                }
                break;
            }
            $json = $res->json();
            $all = array_merge($all, array_values(array_filter((array) ($json['messages'] ?? []), 'is_array')));
            $pageId = self::nextPageId($json['nextPage'] ?? null);
            if ($pageId === '') {
                break;
            }
        }
        return array_slice($all, 0, $limit);
    }

    /** Oznacza wątek jako przeczytany na Allegro. PUT /messaging/threads/{id}/read (beta.v1) */
    public function markThreadRead(string $threadId): bool
    {
        $res = $this->versionedRequest('PUT', '/messaging/threads/' . rawurlencode($threadId) . '/read', [], ['read' => true], self::MESSAGING);
        if ($res === null) {
            return false;
        }
        Logger::apiResponse('allegro', 'PUT', '/messaging/threads/{id}/read', $res->status, 'wątek przeczytany');
        return $res->isSuccess();
    }

    /**
     * Wysyła wiadomość w wątku (także w Problemie z zakupem). POST /messaging/threads/{id}/messages (beta.v1).
     * Status 0 = brak odpowiedzi (nie wiadomo, czy wiadomość doszła).
     * @return array{ok:bool,status:int,message:string}
     */
    public function sendMessage(string $threadId, string $text): array
    {
        $res = $this->versionedRequest('POST', '/messaging/threads/' . rawurlencode($threadId) . '/messages', [], ['text' => $text], self::MESSAGING);
        if ($res === null) {
            return ['ok' => false, 'status' => -1, 'message' => 'Brak tokenu Allegro — połącz konto.'];
        }
        Logger::apiResponse('allegro', 'POST', '/messaging/threads/{id}/messages', $res->status, 'wyślij wiadomość');
        if (!$res->isSuccess()) {
            return ['ok' => false, 'status' => $res->status, 'message' => $this->firstError($res)];
        }
        return ['ok' => true, 'status' => $res->status, 'message' => 'Wysłano.'];
    }

    /**
     * Login połączonego konta (GET /me), zapamiętany na godzinę — do rozpoznania, kto jest kim
     * w wątkach, gdzie Allegro podaje uczestników z rolą USER. '' = nie udało się pobrać.
     */
    public function accountLogin(): string
    {
        if ($this->login !== null) {
            return $this->login;
        }
        $cached = $_SESSION['allegro_account_login'] ?? null;
        if (is_array($cached) && ($cached['t'] ?? 0) > time() - 3600) {
            return $this->login = (string) $cached['login'];
        }
        $res = $this->versionedRequest('GET', '/me', [], null, 'application/vnd.allegro.public.v1+json');
        $this->login = $res !== null && $res->isSuccess() ? (string) ($res->json()['login'] ?? '') : '';
        if ($this->login !== '' && session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['allegro_account_login'] = ['t' => time(), 'login' => $this->login];
        }
        return $this->login;
    }

    /**
     * Wspólny autoryzowany GET z parametrami query. Zwraca null gdy brak tokenu.
     * @param array<string,string|int> $query
     */
    private function authedGet(string $path, array $query = []): ?HttpResponse
    {
        $token = $this->accessToken();
        if ($token === null || $token === '') {
            return null;
        }

        $url = $this->apiBaseUrl() . $path . ($query !== [] ? '?' . http_build_query($query) : '');
        $res = $this->sendAuthedGet($url, $token);

        // Access token Allegro żyje 12 godzin i odświeża go cron (cli/refresh_tokens.php)
        // co 5 minut. Gdy cron nie chodzi albo przepadł jeden przebieg, panel dostawał
        // samo 401 i nic z tym nie robił - użytkownik widział "kod 401" przy sprawnym
        // koncie. Dlatego próbujemy odświeżyć token w locie i powtórzyć zapytanie raz.
        if ($res->status === 401 && $this->tryRefreshAccessToken()) {
            $fresh = $this->accessToken();
            if ($fresh !== null && $fresh !== '' && $fresh !== $token) {
                $res = $this->sendAuthedGet($url, $fresh);
            }
        }

        return $res;
    }

    private function sendOfferQuantity(string $offerId, int $quantity, string $token): HttpResponse
    {
        return Http::request('PATCH', $this->apiBaseUrl() . "/sale/product-offers/{$offerId}", [
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/vnd.allegro.public.v1+json',
            'Content-Type'  => 'application/vnd.allegro.public.v1+json',
        ] + $this->uaHeader(), ['stock' => ['available' => $quantity]]);
    }

    private function sendAuthedGet(string $url, string $token): HttpResponse
    {
        return Http::request('GET', $url, [
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/vnd.allegro.public.v1+json',
        ] + $this->uaHeader());
    }

    /**
     * Jedna próba odświeżenia tokenu na żywotność klienta. Zwraca true, gdy
     * w bazie jest już nowy access_token i warto powtórzyć zapytanie.
     */
    private function tryRefreshAccessToken(): bool
    {
        if ($this->refreshAttempted) {
            return false;
        }
        $this->refreshAttempted = true;

        $refreshToken = $this->integrations->find(self::PLATFORM)['refresh_token'] ?? null;
        if (!is_string($refreshToken) || $refreshToken === '') {
            Logger::warn('Allegro: 401 i brak refresh_token - konto trzeba połączyć ponownie');
            return false;
        }

        Logger::info('Allegro: 401 - próbuję odświeżyć access_token w locie');
        return $this->refreshAccessToken($refreshToken);
    }

    /**
     * Czytelny komunikat dla panelu. 401 po nieudanym odświeżeniu znaczy, że
     * wygasł także refresh_token (Allegro daje mu ok. 3 miesiące) - jedyne
     * wyjście to ponowna autoryzacja konta.
     */
    private function authErrorMessage(HttpResponse $res): string
    {
        if ($res->status === 401) {
            return 'Token Allegro wygasł i nie udało się go odświeżyć — połącz konto ponownie '
                . '(Allegro → Ustawienia). Sprawdź też, czy działa cron odświeżania tokenów.';
        }
        return "Allegro odpowiedziało kodem {$res->status}.";
    }

    private function accessToken(): ?string
    {
        return $this->integrations->find(self::PLATFORM)['access_token'] ?? null;
    }

    private function basicAuthHeaders(): array
    {
        return [
            'Authorization' => 'Basic ' . base64_encode(
                ($this->config['client_id'] ?? '') . ':' . ($this->config['client_secret'] ?? '')
            ),
            'Content-Type'  => 'application/x-www-form-urlencoded',
        ] + $this->uaHeader();
    }

    /**
     * Allegro wymaga nagłówka User-Agent na KAŻDYM zapytaniu (jego brak grozi
     * zablokowaniem klucza API) - patrz config/config.php ('allegro.user_agent',
     * edytowalne w panelu: Marketplace → Allegro → Ustawienia).
     */
    private function uaHeader(): array
    {
        $ua = trim((string) ($this->config['user_agent'] ?? ''));
        return $ua !== '' ? ['User-Agent' => $ua] : [];
    }

    private function persistTokenResponse(array $json): bool
    {
        if (empty($json['access_token'])) {
            Logger::error('Allegro token response bez access_token', $json);
            return false;
        }
        $expiresIn = (int) ($json['expires_in'] ?? 0);
        $expiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify("+{$expiresIn} seconds");
        $this->integrations->saveTokens(self::PLATFORM, $json['access_token'], $json['refresh_token'] ?? null, $expiresAt);
        Logger::info('Allegro: zapisano nowe tokeny', ['expires_at' => $expiresAt->format('c')]);
        return true;
    }
}
