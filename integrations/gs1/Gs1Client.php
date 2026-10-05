<?php
declare(strict_types=1);

namespace PasePlugin\Gs1;

use Pase\Support\Http;
use Pase\Support\Logger;

/**
 * Klient API MojeGS1 v2 (https://mojegs1.pl/api/v2/index.html).
 *
 * Basic Auth (login i hasło API z MojeGS1), limit 60 zapytań na minutę z jednego IP.
 * Lista produktów stronicowana page[offset] = numer strony (od 1).
 */
final class Gs1Client
{
    public const BASE_URL = 'https://mojegs1.pl';

    /** @var callable(string,string,array,?string):array{0:int,1:string} */
    private $transport;

    /**
     * @param array<string,mixed> $config login, password (z konta integracji)
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
        return trim((string) ($this->config['login'] ?? '')) !== '' && (string) ($this->config['password'] ?? '') !== '';
    }

    /** @return array{0:int,1:array<string,mixed>} status HTTP i zdekodowany JSON (albo []) */
    public function request(string $method, string $path, ?array $body = null): array
    {
        if (!$this->configured()) {
            throw new \RuntimeException('Brak danych dostępu do MojeGS1. Wpisz je w Konfiguracja → Integracje → GS1.');
        }
        $headers = [
            'Authorization' => 'Basic ' . base64_encode(trim((string) $this->config['login']) . ':' . (string) $this->config['password']),
            'Accept' => 'application/json',
        ];
        $json = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        [$status, $raw] = ($this->transport)($method, self::BASE_URL . $path, $headers, $json);
        Logger::apiResponse('gs1', $method, self::BASE_URL . $path, $status, '');
        $data = json_decode((string) $raw, true);
        return [(int) $status, is_array($data) ? $data : []];
    }

    /** Strona listy kart (sortowanie po nazwie). @return array{0:int,1:array<string,mixed>} */
    public function listProducts(int $page, int $limit): array
    {
        return $this->request('GET', '/api/v2/products?page%5Boffset%5D=' . $page . '&page%5Blimit%5D=' . $limit . '&sort=name');
    }

    /** @return array{0:int,1:array<string,mixed>} 200 = karta istnieje, 404 = numer wolny */
    public function getProduct(string $gtin): array
    {
        return $this->request('GET', '/api/v2/products/' . $gtin);
    }

    /** Tworzy albo nadpisuje kartę - wołać tylko po sprawdzeniu getProduct() === 404. @return array{0:int,1:array<string,mixed>} */
    public function putProduct(string $gtin, array $attributes): array
    {
        return $this->request('PUT', '/api/v2/products/' . $gtin, ['data' => ['type' => 'products', 'id' => $gtin, 'attributes' => $attributes]]);
    }

    /** Test połączenia: pierwsza strona listy produktów. @return array{ok:bool,message:string} */
    public function test(): array
    {
        try {
            [$st, $data] = $this->listProducts(1, 1);
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        return $st === 200
            ? ['ok' => true, 'message' => 'Połączono z MojeGS1.']
            : ['ok' => false, 'message' => self::errorMessage($st, $data)];
    }

    /** Komunikat błędu z odpowiedzi MojeGS1 (ExternalApiResponse: title, detail, errors[field,message]). */
    public static function errorMessage(int $status, array $data): string
    {
        if ($status === 0) {
            return 'Brak połączenia z MojeGS1 (sprawdź internet na serwerze).';
        }
        if ($status === 401) {
            return 'MojeGS1 odrzuciło login lub hasło API (401). Wygeneruj dane w MojeGS1 → Moje dane → Profile użytkowników → Menu → Zmień dane api i wpisz je ponownie.';
        }
        if ($status === 429) {
            return 'Przekroczony limit MojeGS1 (60 zapytań na minutę). Spróbuj za minutę.';
        }
        $parts = [];
        foreach (['title', 'detail'] as $k) {
            if (!empty($data[$k]) && is_string($data[$k])) {
                $parts[] = $data[$k];
            }
        }
        foreach ((array) ($data['errors'] ?? []) as $e) {
            if (is_array($e)) {
                $field = preg_replace('/^data\.attributes\./', '', (string) ($e['field'] ?? ''));
                $parts[] = ($field !== '' ? self::fieldLabel($field) . ': ' : '') . (string) ($e['message'] ?? '');
            }
        }
        return 'MojeGS1 (HTTP ' . $status . '): ' . ($parts ? implode(' · ', $parts) : 'nieoczekiwana odpowiedź.');
    }

    public static function fieldLabel(string $field): string
    {
        return [
            'brandName' => 'Marka', 'subBrandName' => 'Podmarka', 'commonName' => 'Nazwa zwyczajowa', 'variant' => 'Wariant',
            'gpcCode' => 'Kod GPC', 'netContent' => 'Zawartość netto', 'netContentUnit' => 'Jednostka',
            'description' => 'Opis', 'descriptionLanguage' => 'Język opisu', 'targetMarket' => 'Rynek docelowy',
            'productWebsite' => 'Strona produktu', 'internalSymbol' => 'Symbol wewnętrzny', 'status' => 'Status',
        ][$field] ?? $field;
    }
}
