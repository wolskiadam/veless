<?php
declare(strict_types=1);

namespace PaseExt\Meta;

use Pase\Support\Http;

/**
 * Graph API Meta dla jednego konta: strona Facebook (Messenger, oraz Instagram połączony ze stroną)
 * albo konto Instagram z tokenem „Instagram Login”.
 *
 *  - me():            id i nazwa konta (do rozpoznania, które wiadomości są nasze),
 *  - conversations(): ostatnio zmienione rozmowy z ostatnimi wiadomościami (Instagram oddaje treść 20 ostatnich),
 *  - send():          odpowiedź tekstowa (Meta przyjmuje ją do 24 h od ostatniej wiadomości klienta),
 *  - refresh():       przedłużenie tokenu Instagram Login (ważny 60 dni).
 *
 * Token idzie w nagłówku Authorization, nie w adresie, żeby nie trafił do logów.
 */
final class MetaApi
{
    public const VERSION = 'v26.0';
    public const FB_URL = 'https://graph.facebook.com';
    public const IG_URL = 'https://graph.instagram.com';

    /** Pola wiadomości; przy odrzuceniu (np. inne pola w API Instagrama) próbujemy bez załączników. */
    private const MSG_FIELDS = 'id,created_time,from,to,message,attachments';
    private const MSG_FIELDS_MIN = 'id,created_time,from,to,message';

    /**
     * @param string $kind 'page' (token strony, graph.facebook.com) albo 'ig' (token Instagram Login, graph.instagram.com)
     */
    public function __construct(
        private readonly string $kind,
        private readonly string $token,
        private readonly string $version = self::VERSION,
        private readonly ?string $baseUrl = null,
    ) {}

    public static function forPage(string $token, string $version = self::VERSION): self
    {
        return new self('page', $token, $version, getenv('META_FB_URL') ?: self::FB_URL);
    }

    public static function forInstagram(string $token, string $version = self::VERSION): self
    {
        return new self('ig', $token, $version, getenv('META_IG_URL') ?: self::IG_URL);
    }

    /**
     * Konto, do którego należy token.
     * Strona: ['id', 'name', 'ig_id', 'ig_username'] (Instagram połączony ze stroną, gdy jest).
     * Instagram: ['id', 'name' => @nazwa, 'ig_id', 'ig_username'].
     * @return array{id:string,name:string,ig_id:string,ig_username:string}
     */
    public function me(): array
    {
        if ($this->kind === 'ig') {
            $r = $this->get('me', ['fields' => 'id,user_id,username,name']);
            $igId = (string) ($r['user_id'] ?? $r['id'] ?? '');
            return ['id' => (string) ($r['id'] ?? ''), 'name' => (string) ($r['name'] ?? $r['username'] ?? ''),
                'ig_id' => $igId, 'ig_username' => (string) ($r['username'] ?? '')];
        }
        try {
            $r = $this->get('me', ['fields' => 'id,name,instagram_business_account{id,username}']);
        } catch (MetaApiException $e) {
            if ($e->status === 401 || $e->status === 0) {
                throw $e;
            }
            $r = $this->get('me', ['fields' => 'id,name']);   // token bez uprawnień do Instagrama
        }
        return ['id' => (string) ($r['id'] ?? ''), 'name' => (string) ($r['name'] ?? ''),
            'ig_id' => (string) ($r['instagram_business_account']['id'] ?? ''), 'ig_username' => (string) ($r['instagram_business_account']['username'] ?? '')];
    }

    /**
     * Strony, którymi zarządza token użytkownika (np. użytkownika systemowego z Business Managera), z ich tokenami.
     * Dla tokenu strony Meta zwraca błąd - wtedy null.
     * @return list<array{id:string,name:string,access_token:string}>|null
     */
    public function pages(): ?array
    {
        try {
            $r = $this->get('me/accounts', ['fields' => 'id,name,access_token', 'limit' => '100']);
        } catch (MetaApiException $e) {
            if ($e->status === 0 || $e->metaCode === 190) {
                throw $e;
            }
            return null;
        }
        $out = [];
        foreach ((array) ($r['data'] ?? []) as $p) {
            if (is_array($p) && ($p['access_token'] ?? '') !== '') {
                $out[] = ['id' => (string) $p['id'], 'name' => (string) ($p['name'] ?? ''), 'access_token' => (string) $p['access_token']];
            }
        }
        return $out;
    }

    /**
     * Jedna strona rozmów, od ostatnio zmienionej.
     * @param string $platform 'messenger' albo 'instagram'
     * @return array{data:list<array<string,mixed>>,next:?string}
     */
    public function conversations(string $platform, ?string $after = null, int $limit = 25, int $messages = 20): array
    {
        $params = ['platform' => $platform, 'limit' => (string) $limit];
        if ($after !== null && $after !== '') {
            $params['after'] = $after;
        }
        try {
            $r = $this->get('me/conversations', $params + ['fields' => 'id,updated_time,participants,messages.limit(' . $messages . '){' . self::MSG_FIELDS . '}']);
        } catch (MetaApiException $e) {
            if ($e->status !== 400) {
                throw $e;
            }
            $r = $this->get('me/conversations', $params + ['fields' => 'id,updated_time,participants,messages.limit(' . $messages . '){' . self::MSG_FIELDS_MIN . '}']);
        }
        $data = array_values(array_filter((array) ($r['data'] ?? []), 'is_array'));
        $next = isset($r['paging']['next']) ? (string) ($r['paging']['cursors']['after'] ?? '') : '';
        return ['data' => $data, 'next' => $next !== '' ? $next : null];
    }

    /** Imię i nazwisko (Messenger) albo nazwa (Instagram) osoby, gdy rozmowa ich nie podała. */
    public function profile(string $userId): array
    {
        $fields = $this->kind === 'ig' ? 'name,username' : 'name,first_name,last_name';
        try {
            return $this->get(rawurlencode($userId), ['fields' => $fields]);
        } catch (MetaApiException) {
            return [];
        }
    }

    /**
     * Wysyła odpowiedź tekstową. Zwraca id wiadomości.
     */
    public function send(string $recipientId, string $text): string
    {
        $r = $this->call('POST', 'me/messages', [], [
            'recipient' => ['id' => $recipientId],
            'messaging_type' => 'RESPONSE',
            'message' => ['text' => $text],
        ]);
        return (string) ($r['message_id'] ?? '');
    }

    /** Przedłuża token Instagram Login (60 dni od teraz). @return array{token:string,expires_in:int} */
    public function refresh(): array
    {
        $base = rtrim((string) ($this->baseUrl ?? self::IG_URL), '/');
        $r = $this->decode(Http::request('GET', $base . '/refresh_access_token?grant_type=ig_refresh_token',
            ['Authorization' => 'Bearer ' . $this->token]));
        return ['token' => (string) ($r['access_token'] ?? ''), 'expires_in' => (int) ($r['expires_in'] ?? 0)];
    }

    /** Pobiera plik (zdjęcie z wiadomości) z CDN Meta. Zwraca treść albo null. */
    public static function download(string $url, int $maxBytes): ?string
    {
        if (!preg_match('~^https?://~i', $url)) {
            return null;
        }
        $r = Http::request('GET', $url, [], null, 20);
        return $r->isSuccess() && $r->body !== '' && strlen($r->body) <= $maxBytes ? $r->body : null;
    }

    /** @return array<string,mixed> */
    private function get(string $path, array $params = []): array
    {
        return $this->call('GET', $path, $params);
    }

    /** @return array<string,mixed> */
    private function call(string $method, string $path, array $params = [], ?array $body = null): array
    {
        $base = rtrim((string) ($this->baseUrl ?? ($this->kind === 'ig' ? self::IG_URL : self::FB_URL)), '/');
        $url = $base . '/' . $this->version . '/' . $path . ($params !== [] ? '?' . http_build_query($params) : '');
        $headers = ['Authorization' => 'Bearer ' . $this->token];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        return $this->decode(Http::request($method, $url, $headers, $body, 25));
    }

    /** @return array<string,mixed> */
    private function decode(\Pase\Support\HttpResponse $res): array
    {
        if ($res->status === 0) {
            throw new MetaApiException('Brak połączenia z Meta. Spróbuj za chwilę.', 0);
        }
        $json = $res->json();
        if ($res->isSuccess() && !isset($json['error'])) {
            return $json;
        }
        $err = is_array($json['error'] ?? null) ? $json['error'] : [];
        $code = (int) ($err['code'] ?? 0);
        $msg = mb_substr(trim((string) ($err['error_user_msg'] ?? $err['message'] ?? '')), 0, 300);
        $status = $res->status >= 400 ? $res->status : 400;
        throw new MetaApiException(match (true) {
            $code === 190 || $res->status === 401 => 'Meta odrzuciła token (wygasł albo został cofnięty). Wpisz nowy w ustawieniach wtyczki.',
            $code === 10 || $code === 200 || ($code >= 200 && $code < 300) => 'Brak uprawnień w Meta: ' . ($msg !== '' ? $msg : 'token nie ma dostępu do wiadomości.'),
            $code === 4 || $code === 17 || $code === 32 || $code === 613 => 'Meta: przekroczony limit zapytań. CRM spróbuje później.',
            $code === 551 || str_contains($msg, 'outside of allowed window') => 'Minęły 24 godziny od ostatniej wiadomości klienta - Meta nie pozwala już odpowiedzieć z CRM. Odpowiedz w Meta Business Suite.',
            default => 'Meta zwróciła błąd ' . $res->status . ($msg !== '' ? ': ' . $msg : '.'),
        }, $code === 190 ? 401 : $status, $code);
    }
}
