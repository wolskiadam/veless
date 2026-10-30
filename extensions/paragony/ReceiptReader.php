<?php
declare(strict_types=1);

namespace PaseExt\Paragony;

use Pase\Support\Http;

/**
 * Odczyt danych ze zdjęcia paragonu przez OpenAI (ChatGPT): sprzedawca, NIP, data, kwoty, płatność, co kupiono.
 *
 * Zdjęcie idzie do OpenAI jako obraz w zapytaniu Chat Completions, odpowiedź przychodzi jako JSON według schematu
 * (structured outputs), więc nie trzeba niczego wyłuskiwać z tekstu. Nic nie jest zapisywane: wynik tylko wypełnia
 * formularz, a człowiek sprawdza go przed zapisem. Klucz API trzyma rdzeń zaszyfrowany (pole 'secret' w ustawieniach).
 */
final class ReceiptReader
{
    public const ENDPOINT = 'https://api.openai.com/v1/chat/completions';
    public const MODEL = 'gpt-4o-mini';
    private const MAX_BYTES = 8 * 1024 * 1024;

    private const PROMPT = 'Odczytaj dane z polskiego paragonu fiskalnego (faktury uproszczonej) na zdjęciu. '
        . 'seller: nazwa sprzedawcy (firma z nagłówka, np. "Action Poland sp. z o.o."), seller_nip: NIP sprzedawcy (same cyfry), '
        . 'buyer_nip: NIP nabywcy, jeśli jest wydrukowany (same cyfry, bez "PL"), purchase_date: data sprzedaży RRRR-MM-DD, '
        . 'gross: kwota SUMA / DO ZAPŁATY, vat: SUMA PTU (null, gdy jej nie ma), payment: card (karta), cash (gotówka), blik, transfer (przelew) albo pusty tekst, '
        . 'receipt_no: numer paragonu / wydruku, jeśli jest czytelny, description: krótko po polsku, co kupiono (najwyżej 150 znaków, bez cen i kodów). '
        . 'Gdy czegoś nie da się odczytać, zostaw pusty tekst albo null. Nie zgaduj.';

    public function __construct(private readonly string $apiKey, private readonly string $model, private readonly string $endpoint = self::ENDPOINT)
    {
    }

    /**
     * @return array{seller:string,seller_nip:string,buyer_nip:string,purchase_date:string,gross:string,vat:string,payment:string,receipt_no:string,description:string}
     * @throws \RuntimeException z komunikatem dla użytkownika
     */
    public function read(string $path, string $mime): array
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('Brak klucza OpenAI. Wpisz go w Konfiguracja → Wtyczki → Paragony → ⚙ Ustawienia.');
        }
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new \RuntimeException('Odczytać da się tylko zdjęcie JPG, PNG albo WEBP.');
        }
        $data = (string) @file_get_contents($path);
        if ($data === '' || strlen($data) > self::MAX_BYTES) {
            throw new \RuntimeException('Zdjęcie jest puste albo za duże do odczytu (najwyżej 8 MB).');
        }
        $res = Http::request('POST', $this->endpoint, ['Authorization' => 'Bearer ' . $this->apiKey, 'Content-Type' => 'application/json'], [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => self::PROMPT],
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => 'Paragon:'],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . base64_encode($data), 'detail' => 'high']],
                ]],
            ],
            'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'paragon', 'strict' => true, 'schema' => self::schema()]],
        ], 60);
        if ($res->status === 0) {
            throw new \RuntimeException('Brak połączenia z OpenAI. Spróbuj jeszcze raz.');
        }
        if (!$res->isSuccess()) {
            $msg = (string) ($res->json()['error']['message'] ?? '');
            throw new \RuntimeException(match (true) {
                $res->status === 401 => 'OpenAI odrzuciło klucz API. Sprawdź klucz w ustawieniach wtyczki.',
                $res->status === 429 => 'OpenAI: przekroczony limit albo brak środków na koncie API (platform.openai.com → Billing).',
                default => 'OpenAI zwróciło błąd ' . $res->status . ($msg !== '' ? ': ' . mb_substr($msg, 0, 200) : '.'),
            });
        }
        $content = $res->json()['choices'][0]['message']['content'] ?? null;
        $out = is_string($content) ? json_decode($content, true) : null;
        if (!is_array($out)) {
            throw new \RuntimeException('OpenAI nie odczytało paragonu. Zrób wyraźniejsze zdjęcie albo wpisz dane ręcznie.');
        }
        return self::normalize($out);
    }

    /** Oczyszcza odpowiedź do pól formularza (kwoty po polsku, NIP same cyfry, data tylko poprawna). */
    public static function normalize(array $out): array
    {
        $str = static fn(string $k, int $max): string => is_scalar($out[$k] ?? null) ? mb_substr(trim((string) $out[$k]), 0, $max) : '';
        $num = static fn(string $k): string => is_numeric($out[$k] ?? null) && (float) $out[$k] >= 0 ? number_format((float) $out[$k], 2, ',', '') : '';
        $date = $str('purchase_date', 10);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return [
            'seller' => $str('seller', 200),
            'seller_nip' => preg_replace('/\D/', '', $str('seller_nip', 30)) ?? '',
            'buyer_nip' => preg_replace('/\D/', '', $str('buyer_nip', 30)) ?? '',
            'purchase_date' => $d !== false && $d->format('Y-m-d') === $date ? $date : '',
            'gross' => $num('gross'),
            'vat' => $num('vat'),
            'payment' => in_array($out['payment'] ?? '', ['card', 'cash', 'blik', 'transfer'], true) ? (string) $out['payment'] : '',
            'receipt_no' => $str('receipt_no', 64),
            'description' => $str('description', 300),
        ];
    }

    private static function schema(): array
    {
        $text = ['type' => 'string'];
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['seller', 'seller_nip', 'buyer_nip', 'purchase_date', 'gross', 'vat', 'payment', 'receipt_no', 'description'],
            'properties' => [
                'seller' => $text, 'seller_nip' => $text, 'buyer_nip' => $text, 'purchase_date' => $text,
                'gross' => ['type' => ['number', 'null']], 'vat' => ['type' => ['number', 'null']],
                'payment' => ['type' => 'string', 'enum' => ['card', 'cash', 'blik', 'transfer', '']],
                'receipt_no' => $text, 'description' => $text,
            ],
        ];
    }
}
