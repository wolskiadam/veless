<?php
declare(strict_types=1);

namespace PaseExt\Ksef;

use Pase\Support\Http;

/**
 * Klient KSeF API 2.0 (https://api-test.ksef.mf.gov.pl/docs/v2): logowanie tokenem KSeF,
 * sesja interaktywna (wysyłka pojedynczych faktur), status faktury i UPO.
 *
 * Kolejność wywołań przy wysyłce:
 *   login()        POST auth/challenge -> POST auth/ksef-token -> GET auth/{ref} (aż 200) -> POST auth/token/redeem
 *   openSession()  POST sessions/online (klucz AES zaszyfrowany kluczem publicznym MF)
 *   sendInvoice()  POST sessions/online/{ref}/invoices (faktura zaszyfrowana AES, skróty SHA-256)
 *   closeSession() POST sessions/online/{ref}/close
 *   invoiceStatus() GET sessions/{ref}/invoices/{invoiceRef} -> numer KSeF albo powód odrzucenia
 *   invoiceUpo()   GET sessions/{ref}/invoices/{invoiceRef}/upo (XML podpisany przez MF)
 */
final class KsefApi
{
    public const ENVIRONMENTS = [
        'test' => ['api' => 'https://api-test.ksef.mf.gov.pl/v2', 'qr' => 'https://qr-test.ksef.mf.gov.pl', 'label' => 'Testowe (TE)'],
        'demo' => ['api' => 'https://api-demo.ksef.mf.gov.pl/v2', 'qr' => 'https://qr-demo.ksef.mf.gov.pl', 'label' => 'Przedprodukcyjne (DEMO)'],
        'prod' => ['api' => 'https://api.ksef.mf.gov.pl/v2', 'qr' => 'https://qr.ksef.mf.gov.pl', 'label' => 'Produkcyjne'],
    ];

    /** Kod błędu KSeF: nieznany albo wycofany klucz publiczny (rotacja kluczy MF). */
    private const UNKNOWN_KEY = 21470;

    private string $base;
    /** @var callable(string,string,array<string,string>,?string):array{status:int,body:string} */
    private $transport;
    /** @var callable(int):void */
    private $sleep;
    /** @var list<array<string,mixed>>|null */
    private ?array $certificates = null;

    public function __construct(string $environment, ?callable $transport = null, ?callable $sleep = null)
    {
        if (!isset(self::ENVIRONMENTS[$environment])) {
            throw new \InvalidArgumentException('KSeF: nieznane środowisko „' . $environment . '”.');
        }
        $this->base = self::ENVIRONMENTS[$environment]['api'];
        $this->transport = $transport ?? static function (string $method, string $url, array $headers, ?string $body): array {
            $r = Http::request($method, $url, $headers, $body, 60);
            return ['status' => $r->status, 'body' => $r->body];
        };
        $this->sleep = $sleep ?? static function (int $ms): void { usleep($ms * 1000); };
    }

    public static function qrBase(string $environment): string
    {
        return self::ENVIRONMENTS[$environment]['qr'] ?? self::ENVIRONMENTS['test']['qr'];
    }

    /**
     * Logowanie tokenem KSeF w kontekście NIP firmy. Zwraca accessToken (JWT, krótko ważny).
     */
    public function login(string $nip, string $ksefToken): string
    {
        if (!preg_match('/^\d{10}$/', $nip)) {
            throw new \RuntimeException('KSeF: NIP firmy w ustawieniach musi mieć 10 cyfr.');
        }
        if (trim($ksefToken) === '') {
            throw new \RuntimeException('KSeF: brak tokena KSeF. Wpisz go na stronie Faktury KSeF → Połączenie.');
        }
        return $this->withKeyRetry(function () use ($nip, $ksefToken): string {
            $challenge = $this->call('POST', 'auth/challenge');
            $timestampMs = $challenge['timestampMs'] ?? null;
            if (!isset($challenge['challenge']) || !is_numeric($timestampMs)) {
                throw new \RuntimeException('KSeF: niepełna odpowiedź na auth/challenge.');
            }
            $cert = $this->certificate('KsefTokenEncryption');
            $encrypted = KsefCrypto::rsaOaepSha256(trim($ksefToken) . '|' . (string) $timestampMs, KsefCrypto::publicKey($cert['certificate']));
            $init = $this->call('POST', 'auth/ksef-token', [
                'challenge' => (string) $challenge['challenge'],
                'contextIdentifier' => ['type' => 'Nip', 'value' => $nip],
                'encryptedToken' => base64_encode($encrypted),
                'publicKeyId' => (string) $cert['publicKeyId'],
            ]);
            $authToken = (string) ($init['authenticationToken']['token'] ?? '');
            $ref = (string) ($init['referenceNumber'] ?? '');
            if ($authToken === '' || $ref === '') {
                throw new \RuntimeException('KSeF: niepełna odpowiedź na auth/ksef-token.');
            }
            // Uwierzytelnienie jest asynchroniczne: 100 = w toku, 200 = sukces, 4xx = odmowa.
            for ($i = 0; ; $i++) {
                $st = $this->call('GET', 'auth/' . rawurlencode($ref), null, $authToken);
                $code = (int) ($st['status']['code'] ?? 0);
                if ($code === 200) {
                    break;
                }
                if ($code !== 100 || $i >= 20) {
                    throw new \RuntimeException('KSeF odmówił logowania: ' . self::statusText($st['status'] ?? [], 'kod ' . $code));
                }
                ($this->sleep)(min(2000, 300 + 200 * $i));
            }
            $tokens = $this->call('POST', 'auth/token/redeem', null, $authToken);
            $access = (string) ($tokens['accessToken']['token'] ?? '');
            if ($access === '') {
                throw new \RuntimeException('KSeF: brak accessToken w odpowiedzi auth/token/redeem.');
            }
            return $access;
        });
    }

    /**
     * Otwiera sesję interaktywną dla faktur FA(3). Klucz AES i IV zostają po stronie CRM (do szyfrowania faktur).
     * @return array{ref:string,key:string,iv:string}
     */
    public function openSession(string $accessToken): array
    {
        return $this->withKeyRetry(function () use ($accessToken): array {
            $key = random_bytes(32);
            $iv = random_bytes(16);
            $cert = $this->certificate('SymmetricKeyEncryption');
            $r = $this->call('POST', 'sessions/online', [
                'formCode' => ['systemCode' => 'FA (3)', 'schemaVersion' => '1-0E', 'value' => 'FA'],
                'encryption' => [
                    'encryptedSymmetricKey' => base64_encode(KsefCrypto::rsaOaepSha256($key, KsefCrypto::publicKey($cert['certificate']))),
                    'initializationVector' => base64_encode($iv),
                    'publicKeyId' => (string) $cert['publicKeyId'],
                ],
            ], $accessToken);
            $ref = (string) ($r['referenceNumber'] ?? '');
            if ($ref === '') {
                throw new \RuntimeException('KSeF: brak numeru referencyjnego sesji.');
            }
            return ['ref' => $ref, 'key' => $key, 'iv' => $iv];
        });
    }

    /** Wysyła fakturę (XML) w otwartej sesji. Zwraca numer referencyjny faktury. */
    public function sendInvoice(string $accessToken, array $session, string $xml): string
    {
        $encrypted = KsefCrypto::aesEncrypt($xml, $session['key'], $session['iv']);
        $r = $this->call('POST', 'sessions/online/' . rawurlencode($session['ref']) . '/invoices', [
            'invoiceHash' => KsefCrypto::sha256($xml),
            'invoiceSize' => strlen($xml),
            'encryptedInvoiceHash' => KsefCrypto::sha256($encrypted),
            'encryptedInvoiceSize' => strlen($encrypted),
            'encryptedInvoiceContent' => base64_encode($encrypted),
            'offlineMode' => false,
        ], $accessToken);
        $ref = (string) ($r['referenceNumber'] ?? '');
        if ($ref === '') {
            throw new \RuntimeException('KSeF: brak numeru referencyjnego faktury.');
        }
        return $ref;
    }

    /** Zamyka sesję (KSeF zaczyna generować zbiorcze UPO). Błąd zamknięcia nie cofa wysłanej faktury. */
    public function closeSession(string $accessToken, string $sessionRef): void
    {
        $this->call('POST', 'sessions/online/' . rawurlencode($sessionRef) . '/close', null, $accessToken);
    }

    /**
     * Status faktury w sesji: code 100/150 = przetwarzanie, 200 = przyjęta (ksefNumber), >= 400 = odrzucona.
     * @return array<string,mixed>
     */
    public function invoiceStatus(string $accessToken, string $sessionRef, string $invoiceRef): array
    {
        return $this->call('GET', 'sessions/' . rawurlencode($sessionRef) . '/invoices/' . rawurlencode($invoiceRef), null, $accessToken);
    }

    /** UPO faktury (XML). */
    public function invoiceUpo(string $accessToken, string $sessionRef, string $invoiceRef): string
    {
        return $this->raw('GET', 'sessions/' . rawurlencode($sessionRef) . '/invoices/' . rawurlencode($invoiceRef) . '/upo', $accessToken);
    }

    /** Opis statusu KSeF: „opis (szczegóły)”. */
    public static function statusText(mixed $status, string $fallback = ''): string
    {
        if (!is_array($status)) {
            return $fallback;
        }
        $text = trim((string) ($status['description'] ?? ''));
        $details = array_filter(array_map('strval', (array) ($status['details'] ?? [])));
        if ($details !== []) {
            $text .= ($text !== '' ? ': ' : '') . implode('; ', $details);
        }
        return $text !== '' ? $text : $fallback;
    }

    // ------------------------------------------------------------------ HTTP

    /** @return array<string,mixed> */
    private function call(string $method, string $path, ?array $body = null, ?string $bearer = null): array
    {
        $raw = $this->raw($method, $path, $bearer, $body, 'application/json');
        if ($raw === '') {
            return [];
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new \RuntimeException('KSeF: nieczytelna odpowiedź na ' . $path . '.');
        }
        return $json;
    }

    private function raw(string $method, string $path, ?string $bearer = null, ?array $body = null, string $accept = '*/*'): string
    {
        $headers = ['Accept' => $accept];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        if ($bearer !== null) {
            $headers['Authorization'] = 'Bearer ' . $bearer;
        }
        $payload = $body !== null ? json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null;
        $r = ($this->transport)($method, $this->base . '/' . $path, $headers, $payload);
        $status = (int) ($r['status'] ?? 0);
        $text = (string) ($r['body'] ?? '');
        if ($status >= 200 && $status < 300) {
            return $text;
        }
        throw KsefApiException::fromResponse($status, $text, $path);
    }

    /** @return array{certificate:string,publicKeyId:string} aktualny certyfikat MF do danego celu (najpóźniejszy validFrom). */
    private function certificate(string $usage): array
    {
        $this->certificates ??= array_values(array_filter($this->call('GET', 'security/public-key-certificates'), 'is_array'));
        $now = time();
        $best = null;
        foreach ($this->certificates as $c) {
            $from = strtotime((string) ($c['validFrom'] ?? '')) ?: 0;
            $to = strtotime((string) ($c['validTo'] ?? '')) ?: PHP_INT_MAX;
            if (!in_array($usage, (array) ($c['usage'] ?? []), true) || $from > $now || $to < $now || empty($c['certificate'])) {
                continue;
            }
            if ($best === null || $from > (strtotime((string) $best['validFrom']) ?: 0)) {
                $best = $c;
            }
        }
        if ($best === null) {
            throw new \RuntimeException('KSeF nie udostępnia ważnego klucza publicznego ' . $usage . '.');
        }
        return ['certificate' => (string) $best['certificate'], 'publicKeyId' => (string) ($best['publicKeyId'] ?? '')];
    }

    /** Przy rotacji kluczy MF (kod 21470) pobiera certyfikaty od nowa i powtarza operację raz. */
    private function withKeyRetry(callable $op): mixed
    {
        try {
            return $op();
        } catch (KsefApiException $e) {
            if (!in_array(self::UNKNOWN_KEY, $e->codes, true)) {
                throw $e;
            }
            $this->certificates = null;
            return $op();
        }
    }
}
