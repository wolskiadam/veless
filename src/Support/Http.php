<?php
declare(strict_types=1);

namespace Pase\Support;

/**
 * Cienki wrapper na cURL. Każdy serwis (Allegro/Woo/wFirma) używa go,
 * dzięki czemu logowanie Status Code jest w jednym miejscu.
 *
 * W tej iteracji (szkielet + stuby) realne wywołania w serwisach są
 * oznaczone // TODO, ale ta klasa jest gotowa do użycia od razu.
 */
final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = []
    ) {}

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function json(): array
    {
        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : [];
    }
}

final class Http
{
    /**
     * @param array<string,string> $headers
     * @param array<string,mixed>|string|null $body
     */
    public static function request(
        string $method,
        string $url,
        array $headers = [],
        array|string|null $body = null,
        int $timeout = 20
    ): HttpResponse {
        if (Demo::on()) {
            // Wersja demo nie łączy się z żadnym zewnętrznym API.
            return new HttpResponse(503, 'Wersja demo: połączenia z zewnętrznymi usługami są wyłączone.');
        }
        $ch = curl_init();

        $curlHeaders = [];
        foreach ($headers as $k => $v) {
            $curlHeaders[] = "{$k}: {$v}";
        }

        $payload = null;
        if ($body !== null) {
            $payload = is_array($body) ? json_encode($body, JSON_UNESCAPED_UNICODE) : $body;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $curlHeaders,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HEADER         => false,
        ]);

        $responseBody = curl_exec($ch);
        $status       = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);
        curl_close($ch);
        UsageStats::api(self::service($url), $responseBody === false ? 0 : $status);

        if ($responseBody === false) {
            // Błąd transportu (timeout, DNS). Status 0 => worker potraktuje jako retry.
            Logger::error("HTTP transport error: {$curlError}", ['url' => $url]);
            return new HttpResponse(0, $curlError ?: 'transport_error');
        }

        return new HttpResponse($status, (string) $responseBody);
    }

    /** Nazwa usługi do statystyk obciążenia: znane API po nazwie, sklepy po domenie. */
    public static function service(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        foreach (['allegro', 'wfirma', 'blpaczka', 'smsapi', 'inpost', 'empik', 'erli', 'google', 'tiktok'] as $known) {
            if (str_contains($host, $known)) {
                return $known;
            }
        }
        return $host !== '' ? preg_replace('/^www\./', '', $host) : 'inne';
    }
}
