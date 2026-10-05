<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use Pase\Repository\SettingsRepository;

/**
 * Linki do strony zamówienia klienta - osobno dla każdego sklepu.
 *
 * Domyślnie link prowadzi do CRM: {APP_BASE_URL}/order.php?token=…
 * Sklep może mieć własny adres, np. https://twojsklep.pl/zamowienie.php?token=… - na serwerze sklepu
 * leży wtedy mały plik-pośrednik (do pobrania w Konfiguracja → Strona klienta), który przekazuje
 * żądania do CRM po stronie serwera. Klient cały czas widzi domenę sklepu (bez ramki/iframe,
 * więc działa też na iPhone/Safari, gdzie ciasteczka w ramkach są blokowane).
 *
 * Pośrednik przedstawia się nagłówkami X-Pase-Proxy-Url (swój adres - do budowania linków)
 * i X-Pase-Proxy-Key (wspólny klucz). Bez poprawnego klucza nagłówki są ignorowane.
 */
final class ClientLinks
{
    public const URL_MAP_KEY   = 'CLIENT_URL_BY_SOURCE';
    public const PROXY_KEY_KEY = 'CLIENT_PROXY_KEY';

    public function __construct(private readonly PDO $pdo) {}

    private function settings(): SettingsRepository
    {
        return new SettingsRepository($this->pdo);
    }

    /** @return array<string,string> id integracji => adres strony zamówienia w sklepie */
    public function urlMap(): array
    {
        $m = json_decode((string) ($this->settings()->get(self::URL_MAP_KEY, '') ?? ''), true);
        return is_array($m) ? array_map('strval', $m) : [];
    }

    /** @param array<string|int,string> $map */
    public function setUrlMap(array $map): void
    {
        $clean = [];
        foreach ($map as $k => $v) {
            $v = trim((string) $v);
            if ($v !== '' && preg_match('#^https?://[^\s]+$#i', $v)) {
                $clean[(string) (int) $k] = $v;
            }
        }
        $this->settings()->setMany([self::URL_MAP_KEY => json_encode($clean, JSON_UNESCAPED_SLASHES)]);
    }

    public const THEME_MAP_KEY = 'CLIENT_THEME_BY_SOURCE';

    /** @return array<string,array{bg:string,accent:string}> id integracji => kolory strony zamówienia */
    public function themeMap(): array
    {
        $m = json_decode((string) ($this->settings()->get(self::THEME_MAP_KEY, '') ?? ''), true);
        return is_array($m) ? $m : [];
    }

    /** @param array<string|int,array<string,string>> $map */
    public function setThemeMap(array $map): void
    {
        $clean = [];
        foreach ($map as $k => $v) {
            $bg = self::hex((string) ($v['bg'] ?? ''));
            $ac = self::hex((string) ($v['accent'] ?? ''));
            if ($bg !== null || $ac !== null) {
                $clean[(string) (int) $k] = array_filter(['bg' => $bg, 'accent' => $ac]);
            }
        }
        $this->settings()->setMany([self::THEME_MAP_KEY => json_encode($clean)]);
    }

    /**
     * Nadpisanie kolorów strony zamówienia dla sklepu, z którego jest zamówienie (CSS :root) albo ''.
     * Z koloru przycisków wyliczamy odcienie: hover, jasne tło wyróżnień, ciemny tekst i kolor napisu na przycisku.
     */
    public function themeCss(?int $integrationId): string
    {
        $t = $integrationId ? ($this->themeMap()[(string) $integrationId] ?? []) : [];
        $vars = [];
        if (!empty($t['bg']) && ($bg = self::hex($t['bg'])) !== null) {
            $vars[] = "--bg:{$bg}";
        }
        if (!empty($t['accent']) && ($ac = self::hex($t['accent'])) !== null) {
            $vars[] = "--accent:{$ac}";
            $vars[] = '--accent-hover:' . self::mix($ac, '#000000', 0.15);
            $vars[] = '--accent-soft:' . self::mix($ac, '#ffffff', 0.85);
            $vars[] = '--accent-ink:' . self::mix($ac, '#000000', 0.45);
            $vars[] = '--accent-on:' . (self::luminance($ac) > 0.6 ? '#1a1a1a' : '#ffffff');
        }
        return $vars === [] ? '' : ':root{' . implode(';', $vars) . '}';
    }

    /** „#9C6B2E" / „9c6b2e" / „#abc" -> „#9c6b2e"; niepoprawne -> null. */
    public static function hex(string $c): ?string
    {
        $c = strtolower(ltrim(trim($c), '#'));
        if (preg_match('/^[0-9a-f]{3}$/', $c)) {
            $c = $c[0] . $c[0] . $c[1] . $c[1] . $c[2] . $c[2];
        }
        return preg_match('/^[0-9a-f]{6}$/', $c) ? '#' . $c : null;
    }

    private static function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }

    private static function mix(string $a, string $b, float $t): string
    {
        [$r1, $g1, $b1] = self::rgb($a);
        [$r2, $g2, $b2] = self::rgb($b);
        return sprintf('#%02x%02x%02x', (int) round($r1 + ($r2 - $r1) * $t), (int) round($g1 + ($g2 - $g1) * $t), (int) round($b1 + ($b2 - $b1) * $t));
    }

    private static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(static fn($v) => $v / 255, self::rgb($hex));
        return 0.299 * $r + 0.587 * $g + 0.114 * $b;
    }

    /** Wspólny klucz pośredników (tworzony przy pierwszym użyciu). */
    public function proxyKey(): string
    {
        $k = (string) ($this->settings()->get(self::PROXY_KEY_KEY, '') ?? '');
        if (strlen($k) < 32) {
            $k = bin2hex(random_bytes(24));
            $this->settings()->setMany([self::PROXY_KEY_KEY => $k]);
        }
        return $k;
    }

    /** Link do strony zamówienia dla klienta (null, gdy brak tokenu albo adresu bazowego). */
    public function forOrder(array $order): ?string
    {
        $token = (string) ($order['client_token'] ?? '');
        if ($token === '') {
            return null;
        }
        $src = (string) (int) ($order['integration_id'] ?? 0);
        $shopUrl = $this->urlMap()[$src] ?? '';
        if ($shopUrl !== '') {
            return $shopUrl . (str_contains($shopUrl, '?') ? '&' : '?') . 'token=' . urlencode($token);
        }
        $base = rtrim((string) ($this->settings()->get('APP_BASE_URL', '') ?? ''), '/');
        return $base === '' ? null : $base . '/order.php?token=' . urlencode($token);
    }

    /**
     * Żądanie przyszło przez pośrednika sklepu? Zwraca jego adres (do linków) i IP klienta.
     * @return array{self:string,ip:string}|null
     */
    public static function proxyContext(SettingsRepository $settings): ?array
    {
        $url = (string) ($_SERVER['HTTP_X_PASE_PROXY_URL'] ?? '');
        $key = (string) ($_SERVER['HTTP_X_PASE_PROXY_KEY'] ?? '');
        if ($url === '' || $key === '') {
            return null;
        }
        $expected = (string) ($settings->get(self::PROXY_KEY_KEY, '') ?? '');
        if ($expected === '' || !hash_equals($expected, $key) || !preg_match('#^https?://[^\s"\'<>]+$#i', $url)) {
            return null;
        }
        $ip = (string) ($_SERVER['HTTP_X_PASE_CLIENT_IP'] ?? '');
        return ['self' => $url, 'ip' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : ($_SERVER['REMOTE_ADDR'] ?? '')];
    }

    /** Treść pliku-pośrednika do wgrania na serwer sklepu. */
    public function proxyFile(string $crmBase, string $shopName): string
    {
        $crm = var_export(rtrim($crmBase, '/'), true);
        $key = var_export($this->proxyKey(), true);
        $shop = str_replace(['*/', "\n"], ['', ' '], $shopName);
        return <<<PHP
<?php
/*
 * Strona zamówienia klienta — {$shop}.
 * Plik wygenerowany przez CRM (Konfiguracja → Strona klienta). Nie edytuj — w razie zmian pobierz ponownie.
 * Przekazuje żądania do CRM po stronie serwera, więc klient widzi adres Twojego sklepu.
 * Wymaga PHP 7.3+ z rozszerzeniem cURL (standard na hostingach).
 */
\$CRM    = {$crm};
\$KEY    = {$key};
\$COOKIE = 'pase_order_sess';

header('X-Robots-Tag: noindex, nofollow');
if (!function_exists('curl_init')) { http_response_code(500); exit('Serwer sklepu nie ma rozszerzenia cURL.'); }

\$isFile = isset(\$_GET['_file']);
\$query  = \$_GET; unset(\$query['_file']);
\$target = \$CRM . '/' . (\$isFile ? 'order_attachment.php' : 'order.php') . (\$query ? '?' . http_build_query(\$query) : '');
\$https  = (!empty(\$_SERVER['HTTPS']) && \$_SERVER['HTTPS'] !== 'off') || (\$_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
\$self   = (\$https ? 'https' : 'http') . '://' . \$_SERVER['HTTP_HOST'] . strtok(\$_SERVER['REQUEST_URI'], '?');

\$headers = [
    'X-Pase-Proxy-Url: ' . \$self,
    'X-Pase-Proxy-Key: ' . \$KEY,
    'X-Pase-Client-Ip: ' . (\$_SERVER['REMOTE_ADDR'] ?? ''),
    'Accept-Language: ' . (\$_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'pl'),
    'User-Agent: ' . (\$_SERVER['HTTP_USER_AGENT'] ?? 'pase-proxy'),
];
if (!empty(\$_COOKIE[\$COOKIE])) {
    \$c = base64_decode((string) \$_COOKIE[\$COOKIE], true);
    if (\$c !== false && preg_match('/^[A-Za-z0-9_]{1,40}=[A-Za-z0-9,-]{10,128}\$/', \$c)) { \$headers[] = 'Cookie: ' . \$c; }
}

\$ch = curl_init(\$target);
\$opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10];
if ((\$_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    \$post = \$_POST;
    foreach (\$_FILES as \$name => \$f) {
        if (is_array(\$f) && (\$f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file(\$f['tmp_name'])) {
            \$post[\$name] = new CURLFile(\$f['tmp_name'], \$f['type'] ?: 'application/octet-stream', \$f['name']);
        }
    }
    \$opts[CURLOPT_POST] = true;
    \$opts[CURLOPT_POSTFIELDS] = \$_FILES ? \$post : http_build_query(\$post);
}
\$respHeaders = [];
\$opts[CURLOPT_HEADERFUNCTION] = function (\$ch, \$line) use (&\$respHeaders) {
    \$p = strpos(\$line, ':');
    if (\$p !== false) { \$respHeaders[] = [strtolower(trim(substr(\$line, 0, \$p))), trim(substr(\$line, \$p + 1))]; }
    return strlen(\$line);
};
\$opts[CURLOPT_HTTPHEADER] = \$headers;
curl_setopt_array(\$ch, \$opts);
\$body   = curl_exec(\$ch);
\$status = (int) curl_getinfo(\$ch, CURLINFO_HTTP_CODE);
curl_close(\$ch);
if (\$body === false || \$status === 0) { http_response_code(502); exit('Strona zamówienia jest chwilowo niedostępna. Spróbuj za chwilę.'); }

http_response_code(\$status);
foreach (\$respHeaders as [\$name, \$value]) {
    if (in_array(\$name, ['content-type', 'content-disposition', 'cache-control', 'x-content-type-options'], true)) {
        header(ucwords(\$name, '-') . ': ' . \$value);
    } elseif (\$name === 'location') {
        // order.php?token=… -> ten plik
        header('Location: ' . preg_replace('#^(?:' . preg_quote(\$CRM, '#') . '/)?order\\.php#', \$self, \$value));
    } elseif (\$name === 'set-cookie' && preg_match('/^([A-Za-z0-9_]{1,40})=([A-Za-z0-9,-]{10,128})/', \$value, \$m)) {
        setcookie(\$COOKIE, base64_encode(\$m[1] . '=' . \$m[2]), ['expires' => 0, 'path' => strtok(\$_SERVER['REQUEST_URI'], '?'),
            'secure' => \$https, 'httponly' => true, 'samesite' => 'Lax']);
    }
}
echo \$body;

PHP;
    }
}
