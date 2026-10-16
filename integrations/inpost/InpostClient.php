<?php
declare(strict_types=1);

namespace PasePlugin\Inpost;

use Pase\Support\Http;
use Pase\Support\Logger;

/**
 * Klient API InPost ShipX (REST/JSON, dokumentacja: dokumentacja-inpost.atlassian.net → API ShipX).
 *
 * Autoryzacja: nagłówek „Authorization: Bearer <token>". Token i ID organizacji generuje się w Managerze Paczek
 * (Moje konto → API ShipX; ID w zakładce „Ustawienia organizacji"). Sandbox ma osobne konto
 * (sandbox-manager.paczkomaty.pl) i osobny token.
 *
 * Każda metoda zwraca ['ok' => bool, 'message' => string, 'status' => kod HTTP, ...dane]; nie rzuca wyjątków.
 */
final class InpostClient
{
    public const URL_PRODUCTION = 'https://api-shipx-pl.easypack24.net';
    public const URL_SANDBOX    = 'https://sandbox-api-shipx-pl.easypack24.net';

    /**
     * Atrapa transportu w testach: fn(string $method, string $url, array $headers, ?string $body): array{0:int,1:string}
     * (kod HTTP i treść odpowiedzi).
     * @var (callable(string,string,array,?string):array)|null
     */
    public static $transport = null;

    public function __construct(
        private readonly string $token,
        private readonly string $organizationId,
        private readonly bool $sandbox = false
    ) {}

    public function baseUrl(): string
    {
        return $this->sandbox ? self::URL_SANDBOX : self::URL_PRODUCTION;
    }

    /** Dane organizacji (test połączenia). */
    public function organization(): array
    {
        $r = $this->call('GET', '/v1/organizations/' . rawurlencode($this->organizationId));
        return $r['ok'] ? $r + ['organization' => $r['json']] : $r;
    }

    /** Nowa przesyłka (tryb uproszczony: usługa podana w treści, ShipX sam kupuje ofertę). */
    public function createShipment(array $shipment): array
    {
        $r = $this->call('POST', '/v1/organizations/' . rawurlencode($this->organizationId) . '/shipments', $shipment);
        return $r['ok'] ? $r + ['shipment' => $r['json']] : $r;
    }

    public function shipment(string $id): array
    {
        $r = $this->call('GET', '/v1/shipments/' . rawurlencode($id));
        return $r['ok'] ? $r + ['shipment' => $r['json']] : $r;
    }

    /**
     * Etykieta przesyłki (plik). Wymaga statusu confirmed lub dalszego.
     * @param string $format Pdf | Zpl | Epl
     * @param string $type normal (A4) | A6 (10 × 15 cm) | dpi300 (tylko ZPL)
     */
    public function label(string $id, string $format, string $type): array
    {
        $r = $this->call('GET', '/v1/shipments/' . rawurlencode($id) . '/label?' . http_build_query(['format' => $format, 'type' => $type]), null, true);
        return $r['ok'] ? $r + ['content' => $r['body']] : $r;
    }

    /** Anulowanie - tylko przed opłaceniem (created / offers_prepared / offer_selected). */
    public function cancel(string $id): array
    {
        return $this->call('DELETE', '/v1/shipments/' . rawurlencode($id));
    }

    /** Punkty (Paczkomaty, PaczkoPunkty). @param array<string,scalar> $query */
    public function points(array $query): array
    {
        $r = $this->call('GET', '/v1/points?' . http_build_query($query));
        return $r['ok'] ? $r + ['points' => is_array($r['json']['items'] ?? null) ? $r['json']['items'] : []] : $r;
    }

    /** Zlecenie odbioru przez kuriera dla przesyłek w statusie confirmed. */
    public function dispatchOrder(array $order): array
    {
        $r = $this->call('POST', '/v1/organizations/' . rawurlencode($this->organizationId) . '/dispatch_orders', $order);
        return $r['ok'] ? $r + ['dispatch_order' => $r['json']] : $r;
    }

    // ================= Transport =================

    /** @return array{ok:bool,message:string,status:int,body:string,json:array} */
    private function call(string $method, string $path, ?array $body = null, bool $binary = false): array
    {
        $url = $this->baseUrl() . $path;
        $headers = ['Authorization' => 'Bearer ' . $this->token, 'Accept' => $binary ? '*/*' : 'application/json'];
        $payload = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        }
        try {
            if (self::$transport !== null) {
                [$status, $text] = (self::$transport)($method, $url, $headers, $payload);
            } else {
                $res = Http::request($method, $url, $headers, $payload, 30);
                [$status, $text] = [$res->status, $res->body];
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Błąd połączenia z InPost: ' . $e->getMessage(), 'status' => 0, 'body' => '', 'json' => [], 'transport' => true];
        }
        $status = (int) $status;
        $text = (string) $text;
        Logger::apiResponse('inpost', $method, (string) preg_replace('/\?.*$/', '', $path), $status, '');
        $json = json_decode($text, true);
        $json = is_array($json) ? $json : [];
        if ($status === 0) {
            return ['ok' => false, 'message' => 'Brak odpowiedzi z InPost (' . $text . ').', 'status' => 0, 'body' => $text, 'json' => [], 'transport' => true];
        }
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'message' => self::errorMessage($status, $json, $text), 'status' => $status, 'body' => $text, 'json' => $json];
        }
        return ['ok' => true, 'message' => '', 'status' => $status, 'body' => $text, 'json' => $json];
    }

    /** Polskie opisy najczęstszych kluczy błędów walidacji ShipX. */
    private const DETAIL_WORDS = [
        'required' => 'wymagane', 'invalid' => 'niepoprawne', 'too_long' => 'za długie', 'too_short' => 'za krótkie',
        'does_not_exist' => 'nie istnieje', 'invalid_format' => 'zły format', 'not_a_number' => 'to nie liczba',
        'greater_than' => 'za mała wartość', 'less_than_or_equal_to' => 'za duża wartość',
    ];

    /**
     * Czytelny komunikat z odpowiedzi błędu ShipX: {status, error, description, details}.
     * details bywa zagnieżdżone (np. receiver.phone: ["invalid"]) - spłaszczamy do „receiver.phone: niepoprawne".
     */
    public static function errorMessage(int $status, array $json, string $text = ''): string
    {
        if ($json === []) {
            return 'InPost zwrócił błąd HTTP ' . $status . ($text !== '' && strlen($text) < 300 ? ': ' . trim(strip_tags($text)) : '') . '.';
        }
        $error = (string) ($json['error'] ?? '');
        $msg = match ($error) {
            'token_invalid', 'unauthorized'   => 'Nieprawidłowy token API ShipX (sprawdź token i środowisko).',
            'access_forbidden', 'forbidden'   => 'Brak dostępu — token nie należy do tej organizacji.',
            'resource_not_found'              => 'Nie znaleziono zasobu (sprawdź ID organizacji).',
            'no_carriers', 'carrier_unavailable' => 'Organizacja nie ma umowy na wybraną usługę InPost.',
            'invalid_action'                  => 'Niedozwolona operacja dla obecnego statusu przesyłki.',
            default                           => trim((string) ($json['description'] ?? $json['message'] ?? '')),
        };
        if ($msg === '') {
            $msg = $error !== '' ? $error : 'HTTP ' . $status;
        }
        $details = self::flatDetails($json['details'] ?? null);
        if ($details !== []) {
            $msg = rtrim($msg, '.') . ' — ' . implode('; ', array_slice($details, 0, 8));
        }
        return rtrim($msg, '.') . '.';
    }

    /** @return string[] */
    private static function flatDetails(mixed $details, string $prefix = ''): array
    {
        if (!is_array($details)) {
            return is_scalar($details) && (string) $details !== '' && $prefix !== '' ? [$prefix . ': ' . $details] : [];
        }
        $out = [];
        $isList = array_is_list($details);
        foreach ($details as $k => $v) {
            $key = $isList ? $prefix : ($prefix !== '' ? $prefix . '.' . $k : (string) $k);
            if (is_array($v)) {
                $out = array_merge($out, self::flatDetails($v, $key));
            } elseif (is_scalar($v)) {
                $word = self::DETAIL_WORDS[(string) $v] ?? (string) $v;
                $out[] = $key !== '' ? $key . ': ' . $word : $word;
            }
        }
        return array_values(array_unique($out));
    }
}
