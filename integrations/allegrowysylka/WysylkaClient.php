<?php
declare(strict_types=1);

namespace PasePlugin\Allegrowysylka;

use Pase\Support\Http;
use Pase\Support\HttpResponse;
use Pase\Support\Logger;
use PasePlugin\Allegro\AllegroClient;

/**
 * Klient Shipment Management API („Wysyłam z Allegro").
 * Dokumentacja: developer.allegro.pl -> poradnik „Wysyłam z Allegro".
 *
 * Nie loguje się sam - korzysta z połączenia OAuth konta Allegro (wtyczka `allegro`):
 * token, adres API (produkcja/sandbox) i User-Agent bierze z AllegroClient.
 * Tworzenie i anulowanie przesyłki jest asynchroniczne: wysyłamy komendę
 * (create-commands / cancel-commands), potem odpytujemy jej status.
 */
class WysylkaClient
{
    public function __construct(private readonly AllegroClient $allegro) {}

    /** Propozycja danych do nadania dla zamówienia (nadawca, odbiorca z punktem, paczka, usługi). */
    public function deliveryProposal(string $checkoutFormId): array
    {
        return $this->json('GET', '/shipment-management/delivery-proposals/' . rawurlencode($checkoutFormId));
    }

    /** Usługi dostawy dostępne na koncie - umowa Allegro i własne umowy (owner = CLIENT, credentialsId). */
    public function deliveryServices(): array
    {
        return $this->json('GET', '/shipment-management/delivery-services');
    }

    /** @return array{ok:bool,data:array,status:int,message:string,command_id:string} */
    public function createCommand(array $input): array
    {
        $id = self::uuid4();
        return $this->json('POST', '/shipment-management/shipments/create-commands', ['commandId' => $id, 'input' => $input])
            + ['command_id' => $id];
    }

    public function createCommandStatus(string $commandId): array
    {
        return $this->json('GET', '/shipment-management/shipments/create-commands/' . rawurlencode($commandId));
    }

    public function shipment(string $shipmentId): array
    {
        return $this->json('GET', '/shipment-management/shipments/' . rawurlencode($shipmentId));
    }

    /** @return array{ok:bool,data:array,status:int,message:string,command_id:string} */
    public function cancelCommand(string $shipmentId): array
    {
        $id = self::uuid4();
        return $this->json('POST', '/shipment-management/shipments/cancel-commands', ['commandId' => $id, 'input' => ['shipmentId' => $shipmentId]])
            + ['command_id' => $id];
    }

    public function cancelCommandStatus(string $commandId): array
    {
        return $this->json('GET', '/shipment-management/shipments/cancel-commands/' . rawurlencode($commandId));
    }

    /** Proponowane terminy odbioru przez kuriera. */
    public function pickupProposals(array $shipmentIds, array $address, ?string $readyDate = null): array
    {
        $body = ['shipmentIds' => array_values($shipmentIds), 'address' => $address];
        if ($readyDate) {
            $body['readyDate'] = $readyDate;
        }
        return $this->json('POST', '/shipment-management/pickup-proposals', $body);
    }

    /** Zamówienie odbioru przez kuriera. @return array{ok:bool,data:array,status:int,message:string,command_id:string} */
    public function createPickupCommand(array $shipmentIds, array $pickupTime, array $address): array
    {
        $id = self::uuid4();
        return $this->json('POST', '/shipment-management/pickups/create-commands', ['commandId' => $id, 'input' => [
            'shipmentIds' => array_values($shipmentIds),
            'pickupTime'  => $pickupTime,
            'address'     => $address,
        ]]) + ['command_id' => $id];
    }

    public function pickupCommandStatus(string $commandId): array
    {
        return $this->json('GET', '/shipment-management/pickups/create-commands/' . rawurlencode($commandId));
    }

    /** Operacje billingowe zamówienia (tu widać faktyczny koszt przesyłki naliczony przez Allegro). */
    public function billingEntries(string $checkoutFormId): array
    {
        return $this->json('GET', '/billing/billing-entries?order.id=' . rawurlencode($checkoutFormId) . '&limit=100');
    }

    /**
     * Plik etykiety (PDF albo ZPL - format ustalono przy tworzeniu przesyłki).
     * @return array{ok:bool,content:?string,message:string,status:int}
     */
    public function label(array $shipmentIds, string $pageSize): array
    {
        $res = $this->send('POST', '/shipment-management/label', [
            'shipmentIds' => array_values($shipmentIds),
            'pageSize'    => $pageSize === 'A6' ? 'A6' : 'A4',
            'cutLine'     => $pageSize !== 'A6',
        ], 'application/octet-stream');
        if ($res === null) {
            return ['ok' => false, 'content' => null, 'message' => 'Konto Allegro nie jest połączone.', 'status' => 0];
        }
        if (!$res->isSuccess()) {
            return ['ok' => false, 'content' => null, 'message' => self::error($res), 'status' => $res->status];
        }
        return ['ok' => true, 'content' => $res->body, 'message' => '', 'status' => $res->status];
    }

    // ---------------------------------------------------------------

    /** @return array{ok:bool,data:array<string,mixed>,status:int,message:string} */
    private function json(string $method, string $path, ?array $body = null): array
    {
        $res = $this->send($method, $path, $body, 'application/vnd.allegro.public.v1+json');
        if ($res === null) {
            return ['ok' => false, 'data' => [], 'status' => 0, 'message' => 'Konto Allegro nie jest połączone — połącz je w Integracje → Allegro.'];
        }
        if (!$res->isSuccess()) {
            Logger::warn('Wysyłam z Allegro: błąd API', ['path' => $path, 'status' => $res->status, 'body' => mb_substr($res->body, 0, 800)]);
            return ['ok' => false, 'data' => $res->json(), 'status' => $res->status, 'message' => self::error($res)];
        }
        return ['ok' => true, 'data' => $res->json(), 'status' => $res->status, 'message' => ''];
    }

    /** Zapytanie z tokenem; przy 401 jedno odświeżenie tokenu i powtórka. */
    private function send(string $method, string $path, ?array $body, string $accept): ?HttpResponse
    {
        $token = $this->allegro->bearerToken();
        if ($token === null) {
            return null;
        }
        $do = function (string $t) use ($method, $path, $body, $accept): HttpResponse {
            $headers = ['Authorization' => "Bearer {$t}", 'Accept' => $accept] + $this->allegro->userAgentHeaders();
            if ($body !== null) {
                $headers['Content-Type'] = 'application/vnd.allegro.public.v1+json';
            }
            return Http::request($method, $this->allegro->apiBase() . $path, $headers, $body, 40);
        };
        $res = $do($token);
        if ($res->status === 401 && ($fresh = $this->allegro->refreshBearerToken()) !== null && $fresh !== $token) {
            $res = $do($fresh);
        }
        Logger::apiResponse('allegro', $method, $path, $res->status, 'wysyłam z allegro');
        return $res;
    }

    /** Czytelny komunikat z błędu Allegro. */
    public static function error(HttpResponse $res): string
    {
        $msgs = self::messages($res->json()['errors'] ?? []);
        if ($msgs !== '') {
            return $msgs;
        }
        return match (true) {
            $res->status === 401 => 'Allegro odrzuciło token (401) — połącz konto Allegro ponownie.',
            $res->status === 403 => 'Brak uprawnień (403) — aplikacja Allegro nie ma dostępu do „Wysyłam z Allegro” albo usługa nie jest włączona na koncie.',
            $res->status === 404 => 'Allegro nie znalazło zasobu (404).',
            default              => "Allegro odpowiedziało kodem {$res->status}.",
        };
    }

    /** Lista errors[] z Allegro -> jeden tekst. */
    public static function messages(array $errors): string
    {
        $out = [];
        foreach ($errors as $e) {
            $m = is_array($e) ? ($e['userMessage'] ?? $e['message'] ?? null) : null;
            if (is_string($m) && $m !== '') {
                $out[] = $m . (!empty($e['path']) ? " ({$e['path']})" : '');
            }
        }
        return implode('; ', array_unique($out));
    }

    private static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
