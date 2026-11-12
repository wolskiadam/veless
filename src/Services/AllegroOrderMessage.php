<?php
declare(strict_types=1);

namespace Pase\Services;

/**
 * Wiadomość ze strony zamówienia do kupującego z Allegro.
 * E-mail na zanonimizowany adres @allegromail.pl nie nadaje się do tego: Allegro wycina z niego
 * treść (cytat), a kupujący dostawał tylko „Masz nową wiadomość dotyczącą zamówienia…". Dlatego
 * zamówienia z Allegro idą przez Centrum wiadomości Allegro (POST /messaging/messages), z przypięciem
 * do zamówienia i ewentualnym załącznikiem.
 */
final class AllegroOrderMessage
{
    /** Limit długości jednej wiadomości w Centrum wiadomości Allegro. */
    public const MAX_TEXT = 2000;
    /** Typy plików, które przyjmuje Allegro w załącznikach wiadomości. */
    public const ATTACHMENT_TYPES = [
        'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'bmp' => 'image/bmp', 'tif' => 'image/tiff', 'tiff' => 'image/tiff',
    ];

    /** Zamówienia z Allegro mają w CRM numery od 9 000 000 000. */
    public static function isAllegro(array $order): bool
    {
        return (int) ($order['woo_order_id'] ?? 0) >= 9_000_000_000;
    }

    public static function buyerLogin(array $order): string
    {
        return trim((string) (self::payload($order)['buyer']['login'] ?? ''));
    }

    /** Id formularza zakupu Allegro (checkoutForm). */
    public static function checkoutFormId(array $order): string
    {
        $id = trim((string) (self::payload($order)['id'] ?? ''));
        return $id !== '' ? $id : trim((string) ($order['order_number'] ?? ''));
    }

    /**
     * Dzieli tekst na części do MAX_TEXT znaków, najchętniej na granicy akapitu albo linii.
     * @return array<int,string>
     */
    public static function chunks(string $text, int $max = self::MAX_TEXT): array
    {
        $text = trim(str_replace("\r\n", "\n", $text));
        $out = [];
        while (mb_strlen($text) > $max) {
            $part = mb_substr($text, 0, $max);
            $cut = mb_strrpos($part, "\n\n");
            if ($cut === false || $cut < $max / 2) {
                $cut = mb_strrpos($part, "\n");
            }
            if ($cut === false || $cut < $max / 2) {
                $cut = mb_strrpos($part, ' ');
            }
            if ($cut === false || $cut < $max / 2) {
                $cut = $max;
            }
            $out[] = rtrim(mb_substr($text, 0, $cut));
            $text = ltrim(mb_substr($text, $cut));
        }
        if ($text !== '') {
            $out[] = $text;
        }
        return $out;
    }

    /**
     * Wysyła treść (i opcjonalny plik) do kupującego. Załącznik idzie z ostatnią częścią.
     * @param object $client AllegroMessaging (sendBuyerMessage(), uploadMessageAttachment())
     * @param array{name:string,content:string}|null $attachment
     * @throws \RuntimeException gdy Allegro nie przyjęło wiadomości
     */
    public static function send(object $client, array $order, string $body, ?array $attachment = null): void
    {
        $bare = self::bareAddresses($body);
        if ($bare !== []) {
            throw new \RuntimeException('Wiadomość nie została wysłana: Allegro zatrzyma ją, bo zawiera sam adres strony sklepu ('
                . implode(', ', $bare) . '). Linki do konkretnej strony, np. do zamówienia, są w porządku – usuń z treści goły adres. '
                . 'Jeśli pochodzi z podpisu {{shop_name}}, zmień nazwę nadawcy w Konfiguracja → E-mail (np. „Mój Sklep” zamiast „mojsklep.pl”).');
        }
        $login = self::buyerLogin($order);
        if ($login === '') {
            throw new \RuntimeException('Wiadomość nie została wysłana: brak loginu kupującego Allegro w zamówieniu.');
        }
        $ids = [];
        if ($attachment !== null) {
            $ext = strtolower(pathinfo($attachment['name'], PATHINFO_EXTENSION));
            $mime = self::ATTACHMENT_TYPES[$ext] ?? null;
            if ($mime === null) {
                throw new \RuntimeException('Wiadomość nie została wysłana: Allegro przyjmuje w wiadomościach tylko PDF i obrazy (JPG, PNG, GIF, BMP, TIFF).');
            }
            $up = $client->uploadMessageAttachment($attachment['name'], $mime, $attachment['content']);
            if (empty($up['ok'])) {
                throw new \RuntimeException('Wiadomość nie została wysłana: Allegro nie przyjęło załącznika. ' . ($up['message'] ?? ''));
            }
            $ids[] = (string) $up['id'];
        }
        $parts = self::chunks($body);
        if ($parts === []) {
            $parts = [$attachment !== null ? $attachment['name'] : ''];
        }
        $orderId = self::checkoutFormId($order);
        $last = count($parts) - 1;
        foreach ($parts as $i => $text) {
            $r = $client->sendBuyerMessage($login, $text, $orderId, $i === $last ? $ids : []);
            if (empty($r['ok'])) {
                $sent = $i > 0 ? " (wysłano {$i} z " . count($parts) . ' części)' : '';
                throw new \RuntimeException('Wiadomość nie została wysłana na Allegro' . $sent . ': ' . ($r['message'] ?? ''));
            }
        }
    }

    /**
     * Gołe adresy stron w treści: sama domena („sklep.pl”, „www.sklep.pl”) albo link bez ścieżki
     * („https://sklep.pl/”). Allegro traktuje je jako kierowanie kupującego do zewnętrznego sklepu
     * i zatrzymuje wiadomość. Link do konkretnej strony (ścieżka albo parametry, np. strona
     * zamówienia z tokenem) Allegro przepuszcza; adresy Allegro i e-maile są pomijane.
     * @return array<int,string>
     */
    public static function bareAddresses(string $text): array
    {
        $found = [];
        // Linki z protokołem: zgłaszamy tylko te bez ścieżki i parametrów.
        if (preg_match_all('#https?://([^\s/?\#<>"\')]+)(/?)(?=[\s<>"\'),.;:!]*(?:\s|$))#iu', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                if (!self::isAllegroHost($hit[1])) {
                    $found[] = rtrim($hit[0], '.,;:!');
                }
            }
        }
        // Domeny bez protokołu (poza linkami i adresami e-mail).
        $rest = preg_replace('#https?://\S+#iu', ' ', $text) ?? $text;
        if (preg_match_all('#(?<![@\w./-])((?:www\.)?(?:[a-z0-9-]+\.)+[a-z]{2,24})(?![\w@-]|\.\w|/\S)#iu', $rest, $m2)) {
            foreach ($m2[1] as $host) {
                if (preg_match('#\.(pl|eu|com|net|org|info|biz|shop|store|online|sklep|de|uk|cz|sk|io|co|me)$#i', $host) && !self::isAllegroHost($host)) {
                    $found[] = $host;
                }
            }
        }
        return array_values(array_unique($found));
    }

    private static function isAllegroHost(string $host): bool
    {
        $host = strtolower(preg_replace('#:\d+$#', '', $host) ?? $host);
        return (bool) preg_match('#(^|\.)allegro\.pl$|(^|\.)allegrolokalnie\.pl$#', $host);
    }

    /** @return array<string,mixed> */
    private static function payload(array $order): array
    {
        $p = $order['payload'] ?? null;
        return is_array($p) ? $p : (json_decode((string) $p, true) ?: []);
    }
}
