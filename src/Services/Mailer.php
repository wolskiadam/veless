<?php
declare(strict_types=1);

namespace Pase\Services;

use Pase\Support\Logger;

/**
 * Wysyłka e-mail przez SMTP - lekki klient bez Composera/PHPMailera (shared hosting).
 *
 * Obsługuje: zwykłe (port 25/587 + opcjonalny STARTTLS) oraz SMTPS (port 465, SSL od razu),
 * uwierzytelnianie AUTH LOGIN, treść multipart/alternative (HTML + auto plain-text).
 *
 * Konfiguracja z tabeli settings (klucze MAIL_*). Render zmiennych {{...}} w render().
 */
final class Mailer
{
    /** @param array<string,string> $cfg klucze: host, port, user, pass, secure(tls|ssl|none), from_email, from_name, reply_to (opcjonalnie) */
    public function __construct(private readonly array $cfg) {}

    public function isConfigured(): bool
    {
        return ($this->cfg['host'] ?? '') !== '' && ($this->cfg['from_email'] ?? '') !== '';
    }

    /**
     * Podstawia zmienne {{klucz}} w treści. Nieznane zmienne zostawia puste.
     * @param array<string,string> $vars
     */
    public static function render(string $text, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', static function ($m) use ($vars) {
            return (string) ($vars[$m[1]] ?? '');
        }, $text) ?? $text;
    }

    /** Zmienne, które same są gotowym, bezpiecznym HTML-em (lista produktów escapuje nazwy, logo to <img>). */
    public const HTML_VARS = ['product_list', 'shop_logo'];

    /**
     * Jak render(), ale do treści HTML: wartości (imię kupującego, numer, link…) są escapowane,
     * żeby dane z zamówienia nie mogły wstawić do maila własnych linków ani znaczników.
     * @param array<string,string> $vars
     */
    public static function renderHtml(string $html, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', static function ($m) use ($vars) {
            $value = (string) ($vars[$m[1]] ?? '');
            return in_array($m[1], self::HTML_VARS, true) ? $value : htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }, $html) ?? $html;
    }

    /** Prosta konwersja HTML -> tekst (do wersji alternatywnej). */
    public static function htmlToText(string $html): string
    {
        // Białe znaki po <br> / końcu akapitu to formatowanie źródła HTML, nie tekst (inaczej podwójne odstępy).
        $t = preg_replace('/<\s*br\s*\/?>[ \t]*\r?\n?/i', "\n", $html) ?? $html;
        $t = preg_replace('/<\/\s*(p|div|h[1-6])\s*>\s*/i', "\n\n", $t) ?? $t;
        $t = preg_replace('/<\/\s*(tr|li)\s*>\s*/i', "\n", $t) ?? $t;
        $t = strip_tags($t);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace("/\n{3,}/", "\n\n", $t) ?? $t;
        return trim($t);
    }

    /**
     * Wysyła wiadomość HTML (z auto plain-text) do jednego odbiorcy.
     * Każda próba (udana i nieudana) trafia do dziennika e-maili (EmailLog).
     *
     * @param array{type?:string,template?:?string,order_id?:int|string|null} $meta rodzaj wiadomości
     *        (klucz z EmailLog::TYPES), szablon i zamówienie - do monitoringu wysyłek
     * @param array<int,array{name:string,mime:string,content:string}> $attachments pliki (np. PDF faktury)
     * @return array{0:bool,1:string} [sukces, komunikat]
     */
    public function send(string $toEmail, string $subject, string $htmlBody, array $meta = [], array $attachments = []): array
    {
        [$ok, $msg] = $this->deliver($toEmail, $subject, $htmlBody, $attachments);
        EmailLog::recordAttempt($meta, $toEmail, $subject, (string) ($this->cfg['from_email'] ?? ''), $ok, $ok ? '' : $msg);
        return [$ok, $msg];
    }

    /** @return array{0:bool,1:string} */
    private function deliver(string $toEmail, string $subject, string $htmlBody, array $attachments): array
    {
        if (!$this->isConfigured()) {
            return [false, 'SMTP nie jest skonfigurowany (Konfiguracja → E-mail).'];
        }
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return [false, "Nieprawidłowy adres odbiorcy: {$toEmail}"];
        }

        $host   = $this->cfg['host'];
        $port   = (int) ($this->cfg['port'] ?? 587);
        $secure = $this->cfg['secure'] ?? 'tls';
        $user   = $this->cfg['user'] ?? '';
        $pass   = $this->cfg['pass'] ?? '';
        $fromE  = $this->cfg['from_email'];
        $fromN  = $this->cfg['from_name'] ?? $fromE;

        $transport = $secure === 'ssl' ? "ssl://{$host}" : $host;
        $errno = 0; $errstr = '';
        $fp = @stream_socket_client("{$transport}:{$port}", $errno, $errstr, 15);
        if (!$fp) {
            Logger::error("Mailer: połączenie SMTP nieudane ({$host}:{$port}): {$errstr}");
            return [false, "Połączenie SMTP nieudane: {$errstr}"];
        }
        stream_set_timeout($fp, 15);

        try {
            $this->expect($fp, 220);
            $this->cmd($fp, 'EHLO ' . $this->ehloName($fromE), 250);

            if ($secure === 'tls') {
                $this->cmd($fp, 'STARTTLS', 220);
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('STARTTLS nieudane');
                }
                $this->cmd($fp, 'EHLO ' . $this->ehloName($fromE), 250);
            }

            if ($user !== '') {
                $this->cmd($fp, 'AUTH LOGIN', 334);
                $this->cmd($fp, base64_encode($user), 334);
                $this->cmd($fp, base64_encode($pass), 235);
            }

            $this->cmd($fp, 'MAIL FROM:<' . $fromE . '>', 250);
            $this->cmd($fp, 'RCPT TO:<' . $toEmail . '>', 250);
            $this->cmd($fp, 'DATA', 354);

            $message = $this->buildMessage($fromE, $fromN, $toEmail, $subject, $htmlBody, $attachments);
            fwrite($fp, $message . "\r\n.\r\n");
            $this->expect($fp, 250);

            $this->cmd($fp, 'QUIT', 221, false);
        } catch (\Throwable $e) {
            @fclose($fp);
            Logger::error('Mailer: ' . $e->getMessage());
            return [false, 'Błąd SMTP: ' . $e->getMessage()];
        }
        @fclose($fp);
        Logger::info("Mailer: wysłano e-mail do {$toEmail} (temat: {$subject})");
        \Pase\Support\UsageStats::api('smtp', 200);
        return [true, 'Wysłano.'];
    }

    /**
     * Buduje surową wiadomość multipart/alternative (text + html); z załącznikami całość
     * idzie w multipart/mixed (treść jako pierwsza część, potem pliki w base64).
     * @param array<int,array{name:string,mime:string,content:string}> $attachments
     */
    public function buildMessage(string $fromE, string $fromN, string $to, string $subject, string $html, array $attachments = []): string
    {
        $boundary = 'b_' . bin2hex(random_bytes(8));
        $text = self::htmlToText($html);
        $enc  = static fn(string $s) => '=?UTF-8?B?' . base64_encode($s) . '?=';

        $h   = [];
        $h[] = 'From: ' . $enc($fromN) . ' <' . $fromE . '>';
        $h[] = 'To: <' . $to . '>';
        $replyTo = trim((string) ($this->cfg['reply_to'] ?? ''));
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $h[] = 'Reply-To: <' . $replyTo . '>';
        }
        $h[] = 'Subject: ' . $enc($subject);
        $h[] = 'MIME-Version: 1.0';
        $h[] = 'Date: ' . date('r');
        $h[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $this->ehloName($fromE) . '>';
        $alternative = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

        $b   = [];
        $b[] = '--' . $boundary;
        $b[] = 'Content-Type: text/plain; charset=UTF-8';
        $b[] = 'Content-Transfer-Encoding: base64';
        $b[] = '';
        $b[] = chunk_split(base64_encode($text));
        $b[] = '--' . $boundary;
        $b[] = 'Content-Type: text/html; charset=UTF-8';
        $b[] = 'Content-Transfer-Encoding: base64';
        $b[] = '';
        $b[] = chunk_split(base64_encode($html));
        $b[] = '--' . $boundary . '--';

        if ($attachments === []) {
            $h[] = $alternative;
            return implode("\r\n", $h) . "\r\n\r\n" . implode("\r\n", $b);
        }

        $mixed = 'm_' . bin2hex(random_bytes(8));
        $h[] = 'Content-Type: multipart/mixed; boundary="' . $mixed . '"';
        $m   = ['--' . $mixed, $alternative, '', implode("\r\n", $b)];
        foreach ($attachments as $a) {
            $name = str_replace(['"', "\r", "\n"], '', (string) $a['name']);
            $m[] = '--' . $mixed;
            $m[] = 'Content-Type: ' . ($a['mime'] ?? 'application/octet-stream') . '; name="' . $enc($name) . '"';
            $m[] = 'Content-Transfer-Encoding: base64';
            $m[] = 'Content-Disposition: attachment; filename="' . $enc($name) . '"';
            $m[] = '';
            $m[] = chunk_split(base64_encode((string) $a['content']));
        }
        $m[] = '--' . $mixed . '--';

        return implode("\r\n", $h) . "\r\n\r\n" . implode("\r\n", $m);
    }

    private function ehloName(string $fromEmail): string
    {
        $domain = substr(strrchr($fromEmail, '@') ?: '@localhost', 1);
        return $domain !== '' ? $domain : 'localhost';
    }

    private function cmd($fp, string $cmd, int $expectCode, bool $check = true): void
    {
        fwrite($fp, $cmd . "\r\n");
        if ($check) {
            $this->expect($fp, $expectCode);
        }
    }

    /** Czyta odpowiedź SMTP (z wielolinijkowymi) i weryfikuje kod. */
    private function expect($fp, int $code): void
    {
        $response = '';
        while (($line = fgets($fp, 515)) !== false) {
            $response .= $line;
            // Linia kończąca: "250 ..." (spacja po kodzie); "250-..." to kontynuacja.
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        $got = (int) substr($response, 0, 3);
        if ($got !== $code) {
            throw new \RuntimeException("Oczekiwano {$code}, otrzymano: " . trim($response));
        }
    }
}
