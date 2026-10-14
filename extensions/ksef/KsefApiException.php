<?php
declare(strict_types=1);

namespace PaseExt\Ksef;

/**
 * Błąd odpowiedzi KSeF API z czytelnym komunikatem. Rozumie oba formaty błędów KSeF:
 * dawny ({"exception":{"exceptionDetailList":[{"exceptionCode","exceptionDescription","details"}]}})
 * i problem details ({"title","detail","errors":[...]}).
 */
final class KsefApiException extends \RuntimeException
{
    /** @param list<int> $codes kody błędów KSeF (np. 21470) */
    public function __construct(string $message, public readonly int $httpStatus, public readonly array $codes = [])
    {
        parent::__construct($message);
    }

    public static function fromResponse(int $status, string $body, string $path): self
    {
        $json = json_decode($body, true);
        $parts = [];
        $codes = [];
        if (is_array($json)) {
            foreach ((array) ($json['exception']['exceptionDetailList'] ?? []) as $d) {
                if (!is_array($d)) {
                    continue;
                }
                if (isset($d['exceptionCode']) && is_numeric($d['exceptionCode'])) {
                    $codes[] = (int) $d['exceptionCode'];
                }
                $parts[] = trim(($d['exceptionCode'] ?? '') . ' ' . ($d['exceptionDescription'] ?? '')
                    . (!empty($d['details']) ? ' (' . implode('; ', array_map('strval', (array) $d['details'])) . ')' : ''));
            }
            foreach ((array) ($json['errors'] ?? []) as $e) {
                if (is_array($e)) {
                    if (isset($e['code']) && is_numeric($e['code'])) {
                        $codes[] = (int) $e['code'];
                    }
                    $parts[] = trim(($e['code'] ?? '') . ' ' . ($e['description'] ?? $e['message'] ?? '')
                        . (!empty($e['details']) ? ' (' . implode('; ', array_map('strval', (array) $e['details'])) . ')' : ''));
                } elseif (is_string($e)) {
                    $parts[] = $e;
                }
            }
            if ($parts === []) {
                foreach (['detail', 'title', 'message'] as $k) {
                    if (!empty($json[$k]) && is_string($json[$k])) {
                        $parts[] = $json[$k];
                        break;
                    }
                }
            }
        }
        $parts = array_values(array_filter($parts, static fn(string $p): bool => $p !== ''));
        $what = match (true) {
            $status === 0 => 'brak połączenia z KSeF',
            $status === 401 => 'brak autoryzacji (sprawdź token KSeF i NIP)',
            $status === 403 => 'brak uprawnień (token KSeF musi mieć uprawnienie do wystawiania faktur, a do pobierania faktur zakupowych - do ich przeglądania)',
            $status === 429 => 'przekroczony limit zapytań KSeF, spróbuj za chwilę',
            $status >= 500 => 'KSeF jest chwilowo niedostępny',
            default => 'błąd ' . $status,
        };
        $msg = 'KSeF (' . $path . '): ' . $what . ($parts !== [] ? ' - ' . mb_substr(implode('; ', $parts), 0, 600) : '');
        if ($status === 0 && $body !== '') {
            $msg .= ' (' . mb_substr($body, 0, 200) . ')';
        }
        return new self($msg, $status, $codes);
    }
}
