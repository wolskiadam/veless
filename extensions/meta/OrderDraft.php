<?php
declare(strict_types=1);

namespace PaseExt\Meta;

/**
 * Dane do formularza „Nowe zamówienie” z rozmowy: imię i nazwisko rozmówcy oraz to, co klient napisał
 * w zaznaczonych wiadomościach - telefon, e-mail, adres (ulica, kod, miasto) i kod paczkomatu.
 *
 * Wyszukiwanie jest proste (wyrażenia regularne na polskie formaty), dlatego formularz pokazuje,
 * skąd są dane, a pola wypełnia tylko te puste.
 */
final class OrderDraft
{
    /**
     * @param array<string,mixed> $conv rozmowa (Inbox::get)
     * @param list<array<string,mixed>> $messages wiadomości, z których brać dane (zaznaczone albo wszystkie od klienta)
     * @param bool $note zaznaczone wiadomości trafiają też do „Uwagi do zamówienia”
     * @return array<string,string> pola formularza order_new.php
     */
    public static function form(array $conv, array $messages, bool $note = true): array
    {
        $form = [];
        [$first, $last] = self::splitName((string) $conv['peer_name']);
        $form['billing_first_name'] = $first;
        $form['billing_last_name'] = $last;

        $text = implode("\n", array_map(static fn(array $m): string => (string) $m['body'], array_filter($messages, static fn(array $m): bool => (int) $m['from_peer'] === 1)));
        $found = self::extract($text);
        foreach (['email' => 'billing_email', 'phone' => 'billing_phone', 'address' => 'billing_address_1', 'postcode' => 'billing_postcode', 'city' => 'billing_city'] as $k => $field) {
            if ($found[$k] !== '') {
                $form[$field] = $found[$k];
            }
        }
        if ($found['name'] !== '' && $first === '') {
            [$form['billing_first_name'], $form['billing_last_name']] = self::splitName($found['name']);
        }
        if ($found['locker'] !== '') {
            $form['pp_id'] = $found['locker'];
            $form['shipping_method'] = 'InPost Paczkomat';
        }
        $quoted = [];
        foreach ($note ? $messages : [] as $m) {
            $b = trim((string) $m['body']);
            if ($b !== '') {
                $quoted[] = ((int) $m['from_peer'] === 1 ? '' : 'My: ') . $b;
            }
        }
        if ($quoted !== []) {
            $form['customer_note'] = mb_substr(implode("\n", $quoted), 0, 2000);
        }
        return array_filter($form, static fn(string $v): bool => $v !== '');
    }

    /**
     * Dane kontaktowe w tekście wiadomości.
     * @return array{email:string,phone:string,address:string,postcode:string,city:string,locker:string,name:string}
     */
    public static function extract(string $text): array
    {
        $out = ['email' => '', 'phone' => '', 'address' => '', 'postcode' => '', 'city' => '', 'locker' => '', 'name' => ''];
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $m)) {
            $out['email'] = mb_strtolower($m[0]);
        }
        // Telefon: 9 cyfr (z +48 / 0048 albo bez), grupy rozdzielone spacją lub kreską. Nie bierzemy kodu pocztowego ani numeru konta.
        if (preg_match('/(?<![\d\-])(?:(?:\+|00)48[\s\-]?)?(\d{3}[\s\-]?\d{3}[\s\-]?\d{3})(?![\d\-])/', $text, $m)) {
            $out['phone'] = preg_replace('/\D/', '', $m[1]) ?? '';
        }
        // Paczkomat InPost: np. WAW123M, KRA01N, POZ08A (3 litery, 2-4 cyfry, 1-2 litery), także po słowie „paczkomat”.
        if (preg_match('/paczkomat\w*[\s:]+([A-Z]{3}\d{2,4}[A-Z]{1,2})\b/iu', $text, $m) || preg_match('/\b([A-Z]{3}\d{2,4}[A-Z]{1,2})\b/', $text, $m)) {
            $out['locker'] = strtoupper($m[1]);
        }

        // Kod pocztowy i miasto, ulica w tej samej linii przed kodem albo w linii wyżej.
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $text) ?: []), static fn(string $l): bool => $l !== ''));
        foreach ($lines as $i => $line) {
            if (!preg_match('/(?<!\d)(\d{2})\s?-\s?(\d{3})(?!\d)\s*,?\s*([\p{L}][\p{L}\- ]{1,40}[\p{L}])?/u', $line, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $out['postcode'] = $m[1][0] . '-' . $m[2][0];
            $out['city'] = isset($m[3]) ? self::cleanCity((string) $m[3][0]) : '';
            $before = trim(rtrim(substr($line, 0, (int) $m[0][1]), " ,;"));
            $street = self::street($before);
            if ($street === '' && $i > 0) {
                $street = self::street($lines[$i - 1]);
                if ($street !== '' && $i > 1 && self::looksLikeName($lines[$i - 2])) {
                    $out['name'] = $lines[$i - 2];
                }
            } elseif ($street !== '' && $i > 0 && self::looksLikeName($lines[$i - 1])) {
                $out['name'] = $lines[$i - 1];
            }
            $out['address'] = $street;
            break;
        }
        if ($out['address'] === '') {
            foreach ($lines as $line) {
                $s = self::street($line);
                if ($s !== '' && preg_match('/\b(ul|al|os|pl)\.\s/iu', $s)) {
                    $out['address'] = $s;
                    break;
                }
            }
        }
        return $out;
    }

    /** Ulica z numerem z kawałka tekstu („ul. Lipowa 12/3”, „Polna 5a m. 2”), bez telefonu i e-maila. */
    private static function street(string $s): string
    {
        $s = trim(preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '', $s) ?? '');
        // Ostatni fragment po przecinku, w którym jest nazwa i numer domu.
        $parts = array_reverse(array_map('trim', explode(',', $s)));
        foreach ($parts as $p) {
            if (preg_match('/((?:ul\.|al\.|os\.|pl\.|ulica|aleja|osiedle)?\s*[\p{L}][\p{L}\.\- ]{1,60}?\s+\d{1,4}[a-zA-Z]?(?:\s*[\/m]\.?\s*\d{1,4}[a-zA-Z]?)?(?:\s+m\.?\s*\d{1,4})?)\s*$/u', $p, $m)) {
                return trim(preg_replace('/\s+/u', ' ', $m[1]) ?? '');
            }
        }
        return '';
    }

    private static function cleanCity(string $c): string
    {
        $c = trim(preg_replace('/\s+/u', ' ', $c) ?? '');
        // Bez doklejonych słów z dalszej części zdania („Kraków telefon …”).
        $c = preg_replace('/\s+(tel|telefon|nr|numer|email|e-mail|mail|paczkomat)\b.*$/iu', '', $c) ?? $c;
        return mb_convert_case($c, MB_CASE_TITLE);
    }

    private static function looksLikeName(string $line): bool
    {
        return preg_match('/^[\p{Lu}][\p{Ll}\-]+(\s+[\p{Lu}][\p{Ll}\-]+){1,2}$/u', trim($line)) === 1;
    }

    /** @return array{0:string,1:string} imię, nazwisko */
    public static function splitName(string $name): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '') {
            return ['', ''];
        }
        $parts = explode(' ', $name, 2);
        return [$parts[0], $parts[1] ?? ''];
    }
}
