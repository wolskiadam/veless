<?php
declare(strict_types=1);

namespace PasePlugin\Blpaczka;

use Pase\Support\Http;
use Pase\Support\Logger;

/**
 * Klient API BLPaczka (broker: InPost/DPD/DHL... przez jedno API) — logika należąca
 * do WTYCZKI (samowystarczalna, w katalogu integrations/blpaczka/).
 *
 * Auth (AuthDto): { "auth": { "login": ..., "api_key": ... } } w body każdego POST.
 * Korzysta wyłącznie ze wspólnych narzędzi rdzenia (Http, Logger) — bez logiki rdzenia.
 *
 * @param array $config oczekuje: login, api_key, env ('sandbox'|'production')
 */
final class BlpaczkaClient
{
    public function __construct(private readonly array $config) {}

    private function baseUrl(): string
    {
        return ($this->config['env'] ?? 'sandbox') === 'production'
            ? 'https://api.blpaczka.com'
            : 'https://sandbox.blpaczka.com';
    }

    private function auth(): array
    {
        return [
            'login'   => $this->config['login'] ?? '',
            'api_key' => $this->config['api_key'] ?? '',
        ];
    }

    private function post(string $endpoint, array $payload = []): \Pase\Support\HttpResponse
    {
        $url  = $this->baseUrl() . $endpoint;
        $body = array_merge(['auth' => $this->auth()], $payload);
        $res  = Http::request('POST', $url, ['Content-Type' => 'application/json'], $body);
        Logger::apiResponse('blpaczka', 'POST', $endpoint, $res->status);
        return $res;
    }

    /** @return array{ok:bool, message:string, status:int} */
    public function testConnection(): array
    {
        if (($this->config['login'] ?? '') === '' || ($this->config['api_key'] ?? '') === '') {
            return ['ok' => false, 'message' => 'Brak loginu lub klucza API.', 'status' => 0];
        }

        $res = $this->post('/api/getBankSaldo.json');

        if ($res->status === 0) {
            return ['ok' => false, 'message' => 'Brak połączenia z BLPaczka (sprawdź sieć/środowisko).', 'status' => 0];
        }
        if ($res->status === 401 || $res->status === 403) {
            return ['ok' => false, 'message' => 'Odrzucono uwierzytelnianie - sprawdź login i klucz API.', 'status' => $res->status];
        }

        $json = $res->json();
        $success = $json['success'] ?? null;
        if ($res->isSuccess() && ($success === true || $success === 1 || $success === '1' || $success === null)) {
            $saldo = self::parseSaldo($json);
            $msg = 'Połączenie OK — uwierzytelnianie BLPaczka działa.'
                 . ($saldo !== null ? ' Saldo skarbonki: ' . number_format($saldo, 2, ',', ' ') . ' zł.' : '');
            return ['ok' => true, 'message' => $msg, 'status' => $res->status];
        }

        $msg = $json['message'] ?? ('Odpowiedź API: HTTP ' . $res->status);
        return ['ok' => false, 'message' => (string) $msg, 'status' => $res->status];
    }

    /**
     * Saldo skarbonki (prepaid) - getBankSaldo.json. To te środki BLPaczka pobiera przy
     * formie płatności „bank”; płatność odroczona / online ich nie dotyczy.
     * @return array{ok:bool, balance:?float, raw:array, message:string}
     */
    public function getBalance(): array
    {
        $res  = $this->post('/api/getBankSaldo.json');
        $json = $res->json();
        if (!$res->isSuccess() || ($json['success'] ?? true) === false) {
            return ['ok' => false, 'balance' => null, 'raw' => $json,
                    'message' => (string) ($json['message'] ?? ('Nie udało się odczytać salda (HTTP ' . $res->status . ').'))];
        }
        $balance = self::parseSaldo($json);
        return ['ok' => $balance !== null, 'balance' => $balance, 'raw' => $json,
                'message' => $balance !== null ? 'OK' : 'Nieznany format odpowiedzi salda.'];
    }

    /**
     * Kwota z odpowiedzi getBankSaldo. Format nie jest opisany jednoznacznie, więc
     * szukamy liczby: samo `data`, albo pole o nazwie typu saldo/balance/amount/kwota.
     */
    public static function parseSaldo(mixed $json): ?float
    {
        $num = static function (mixed $v): ?float {
            if (is_int($v) || is_float($v)) {
                return (float) $v;
            }
            if (is_string($v)) {
                $t = str_replace([' ', "\u{a0}", 'zł', 'PLN'], '', $v);
                $t = str_replace(',', '.', $t);
                return is_numeric($t) ? (float) $t : null;
            }
            return null;
        };
        if (!is_array($json)) {
            return $num($json);
        }
        if (array_key_exists('data', $json) && ($n = $num($json['data'])) !== null) {
            return $n;
        }
        $walk = static function (array $a) use (&$walk, $num): ?float {
            foreach ($a as $k => $v) {
                if (is_string($k) && preg_match('/saldo|balance|amount|kwota|value/i', $k) && ($n = $num($v)) !== null) {
                    return $n;
                }
            }
            foreach ($a as $k => $v) {
                if (is_array($v) && $k !== 'auth' && ($n = $walk($v)) !== null) {
                    return $n;
                }
            }
            return null;
        };
        return $walk($json);
    }

    /** Lista kurierów dla paczki (CourierSearchDto). @return array surowa odpowiedź */
    public function getCouriers(array $parcel): array
    {
        $payload = array_merge(['type' => 'package', 'origin' => 'PL'], $parcel);
        $res = $this->post('/api/couriers', $payload);
        if (!$res->isSuccess()) {
            return [];
        }
        $json = $res->json();
        return $json['data'] ?? $json['couriers'] ?? $json ?? [];
    }

    /** Formy płatności (getPaymentOptions.json). @return array surowa odpowiedź */
    public function getPaymentOptions(): array
    {
        return $this->post('/api/getPaymentOptions.json')->json();
    }

    /** Wycena (getValuation.json) - bez nadawania. @return array{ok:bool,price:?float,raw:array,message:string} */
    public function getValuation(array $params): array
    {
        $res = $this->post('/api/getValuation.json', ['CourierSearch' => $params]);
        $json = $res->json();

        if (!$res->isSuccess() || ($json['success'] ?? true) === false) {
            return ['ok' => false, 'price' => null, 'raw' => $json, 'message' => (string) ($json['message'] ?? 'Błąd wyceny.')];
        }
        $price = $json['data']['price'] ?? $json['price'] ?? null;
        return ['ok' => true, 'price' => $price !== null ? (float) $price : null, 'raw' => $json, 'message' => 'OK'];
    }

    /**
     * Nadanie (createOrderV2.json) - REALNE, pobiera środki z prepaid.
     * @return array{ok:bool, waybill_no:?string, order_id:?string, price:?float, label_link:?string, raw:array, message:string}
     */
    public function createOrder(array $order): array
    {
        $body = [
            'CourierSearch' => $order['CourierSearch'] ?? [],
            'CartOrder'     => $order['CartOrder'] ?? [],
            'Cart'          => $order['Cart'] ?? [],
        ];
        $res  = $this->post('/api/createOrderV2.json', $body);
        $json = $res->json();

        if (!$res->isSuccess() || ($json['success'] ?? false) === false) {
            return [
                'ok' => false, 'waybill_no' => null, 'order_id' => null, 'price' => null, 'label_link' => null,
                'raw' => $json, 'message' => (string) ($json['message'] ?? 'Nadanie nie powiodło się.'),
            ];
        }

        $data    = $json['data'] ?? [];
        $orders  = $data['Order'] ?? [];
        $first   = is_array($orders) ? ($orders[0] ?? []) : [];
        $waybill = $first['waybill_no'] ?? null;
        $orderId = $first['id'] ?? null;
        $price   = $first['price'] ?? ($data['CartOrder']['price'] ?? null);
        $label   = $data['waybill_link'] ?? null;

        return [
            'ok' => true,
            'waybill_no' => $waybill,
            'order_id' => $orderId !== null ? (string) $orderId : null,
            'price' => $price !== null ? (float) $price : null,
            'label_link' => $label,
            'raw' => $json,
            'message' => (string) ($json['message'] ?? 'Przesyłka nadana.'),
        ];
    }

    /** Anulowanie (cancelOrder.json). @return array{ok:bool, message:string} */
    public function cancelOrder(int $orderId): array
    {
        $res = $this->post('/api/cancelOrder.json', ['Order' => ['id' => $orderId]]);
        $json = $res->json();

        if (!$res->isSuccess() || ($json['success'] ?? false) === false) {
            return ['ok' => false, 'message' => (string) ($json['message'] ?? 'Nie udało się anulować przesyłki.')];
        }
        return ['ok' => true, 'message' => (string) ($json['message'] ?: 'Przesyłka anulowana.')];
    }

    /**
     * Etykieta (getWaybill.json) - zdekodowany plik.
     * @return array{ok:bool, content:?string, filename:?string, mime:?string, message:string}
     */
    public function getWaybill(int $orderId, string $printerType = 'A4'): array
    {
        $res = $this->post('/api/getWaybill.json', [
            'Order' => ['id' => $orderId, 'printer_type' => $printerType],
        ]);
        $json = $res->json();

        if (!$res->isSuccess() || ($json['success'] ?? false) === false) {
            return ['ok' => false, 'content' => null, 'filename' => null, 'mime' => null,
                    'message' => (string) ($json['message'] ?? 'Nie udało się pobrać etykiety.')];
        }

        $doc = $json['data'][0] ?? null;
        if (!is_array($doc) || empty($doc['file'])) {
            return ['ok' => false, 'content' => null, 'filename' => null, 'mime' => null,
                    'message' => 'Etykieta jeszcze niegotowa lub brak danych pliku.'];
        }

        return [
            'ok'       => true,
            'content'  => base64_decode($doc['file'], true) ?: null,
            'filename' => $doc['filename'] ?? ('etykieta_' . $orderId . '.' . ($doc['extension'] ?? 'pdf')),
            'mime'     => $doc['mime'] ?? 'application/pdf',
            'message'  => 'OK',
        ];
    }
}
