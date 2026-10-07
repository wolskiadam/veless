<?php
declare(strict_types=1);

namespace Pase\Services;

use Pase\Support\Http;
use Pase\Support\HttpResponse;
use Pase\Support\Logger;

/**
 * Centrum wiadomości Allegro: nowa wiadomość do kupującego i jej załącznik.
 * Korzysta z połączenia wtyczki Allegro (apiBase(), bearerToken(), refreshBearerToken(), userAgentHeaders()).
 */
final class AllegroMessaging
{
    /** Centrum wiadomości w wersji beta.v1 — od 28.10.2026 Allegro obsługuje /messaging tylko w niej. */
    private const MEDIA = 'application/vnd.allegro.beta.v1+json';

    /** @param object $client AllegroClient */
    public function __construct(private readonly object $client) {}

    /**
     * Wiadomość do kupującego (nowy albo istniejący wątek z nim). POST /messaging/messages (beta.v1);
     * $orderId (checkoutForm) przypina wiadomość do zamówienia.
     * @param array<int,string> $attachmentIds id z uploadMessageAttachment()
     * @return array{ok:bool,message:string}
     */
    public function sendBuyerMessage(string $login, string $text, ?string $orderId = null, array $attachmentIds = []): array
    {
        $body = ['recipient' => ['login' => $login], 'text' => $text];
        if ($orderId !== null && $orderId !== '') {
            $body['order'] = ['id' => $orderId];
        }
        if ($attachmentIds !== []) {
            $body['attachments'] = array_map(static fn(string $id) => ['id' => $id], array_values($attachmentIds));
        }
        $res = $this->request('POST', '/messaging/messages', self::MEDIA, $body);
        if ($res === null) {
            return ['ok' => false, 'message' => 'Brak tokenu Allegro — połącz konto.'];
        }
        Logger::apiResponse('allegro', 'POST', '/messaging/messages', $res->status, 'wiadomość do kupującego');
        return $res->isSuccess() ? ['ok' => true, 'message' => ''] : ['ok' => false, 'message' => self::error($res)];
    }

    /**
     * Załącznik wiadomości: POST /messaging/message-attachments (deklaracja), potem PUT z plikiem.
     * @return array{ok:bool,id:string,message:string}
     */
    public function uploadMessageAttachment(string $fileName, string $contentType, string $bytes): array
    {
        $res = $this->request('POST', '/messaging/message-attachments', self::MEDIA,
            ['filename' => $fileName, 'size' => strlen($bytes)]);
        if ($res === null) {
            return ['ok' => false, 'id' => '', 'message' => 'Brak tokenu Allegro — połącz konto.'];
        }
        Logger::apiResponse('allegro', 'POST', '/messaging/message-attachments', $res->status, 'załącznik wiadomości');
        $id = (string) ($res->json()['id'] ?? '');
        if (!$res->isSuccess() || $id === '') {
            return ['ok' => false, 'id' => '', 'message' => self::error($res)];
        }
        $up = $this->request('PUT', '/messaging/message-attachments/' . rawurlencode($id), $contentType, $bytes);
        Logger::apiResponse('allegro', 'PUT', '/messaging/message-attachments/{id}', $up?->status ?? 0, 'plik załącznika wiadomości');
        if ($up === null || !$up->isSuccess()) {
            return ['ok' => false, 'id' => '', 'message' => $up !== null ? self::error($up) : 'Brak tokenu Allegro — połącz konto.'];
        }
        return ['ok' => true, 'id' => $id, 'message' => ''];
    }

    /** Zapytanie z tokenem; po 401 jedno odświeżenie tokenu i powtórka. Null = konto niepołączone. */
    private function request(string $method, string $path, string $contentType, array|string $body): ?HttpResponse
    {
        $token = $this->client->bearerToken();
        if ($token === null) {
            return null;
        }
        $send = fn(string $t) => Http::request($method, rtrim($this->client->apiBase(), '/') . $path, [
            'Authorization'   => "Bearer {$t}",
            'Accept'          => self::MEDIA,
            'Content-Type'    => $contentType,
            'Accept-Language' => 'pl-PL',
        ] + $this->client->userAgentHeaders(), $body, 60);
        $res = $send($token);
        if ($res->status === 401 && ($fresh = $this->client->refreshBearerToken()) !== null && $fresh !== $token) {
            $res = $send($fresh);
        }
        return $res;
    }

    private static function error(HttpResponse $res): string
    {
        $e = $res->json()['errors'][0] ?? null;
        $msg = is_array($e) ? trim((string) ($e['userMessage'] ?? $e['message'] ?? '')) : '';
        return $msg !== '' ? $msg : "Allegro odpowiedziało kodem {$res->status}.";
    }
}
