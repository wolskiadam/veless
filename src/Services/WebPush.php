<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use Pase\Repository\SettingsRepository;
use Pase\Support\Http;
use Pase\Support\Logger;

/**
 * Powiadomienia push w przeglądarce / na telefonie (Web Push, bez zewnętrznej usługi pośredniczącej).
 *
 * Panel działa jak aplikacja (manifest.php + push-sw.js); urządzenie, które włączyło powiadomienia
 * (dzwoneczek → koło zębate), zapisuje tu swoją subskrypcję. Serwer wysyła wiadomość prosto do usługi
 * push przeglądarki (Apple, Google, Mozilla, Microsoft):
 *  - klucze VAPID (P-256) tworzone przy pierwszym włączeniu, prywatny w settings (szyfrowany przez SecretStore),
 *  - treść szyfrowana wg RFC 8291 (aes128gcm), podpis VAPID wg RFC 8292 (JWT ES256),
 *  - adres usługi push wygasł (404/410) → subskrypcja usuwana.
 *
 * Na iPhonie działa od iOS 16.4, gdy panel jest dodany do ekranu początkowego i otwarty z ikony.
 */
final class WebPush
{
    public const PUBLIC_KEY = 'WEBPUSH_VAPID_PUBLIC';
    public const PRIVATE_KEY = 'WEBPUSH_VAPID_PRIVATE_KEY'; // końcówka _KEY = szyfrowane w bazie
    public const SUBJECT = 'WEBPUSH_SUBJECT';

    /** Usługi push przeglądarek - na inne adresy serwer nic nie wysyła (subskrypcję podaje przeglądarka). */
    private const PUSH_HOSTS = ['push.apple.com', 'fcm.googleapis.com', 'android.googleapis.com', 'push.services.mozilla.com', 'notify.windows.com'];

    /** Starsze zamówienia (sekundy od złożenia) nie wysyłają powiadomienia - to import historii, nie nowe zamówienie. */
    private const MAX_ORDER_AGE = 2 * 86400;

    /** Nagłówek DER klucza publicznego P-256 (SubjectPublicKeyInfo) przed 65 bajtami punktu. */
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /** @var null|\Closure(string,string,array<string,string>,string):array{status:int,body:string} testy podstawiają wysyłkę */
    private ?\Closure $sender = null;

    public function __construct(private readonly PDO $pdo) {}

    public static function migrate(PDO $pdo): void
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                endpoint_hash CHAR(64) NOT NULL,
                endpoint TEXT NOT NULL,
                p256dh VARCHAR(120) NOT NULL,
                auth VARCHAR(40) NOT NULL,
                user_agent VARCHAR(255) NOT NULL DEFAULT '',
                created_at INT NOT NULL,
                last_ok_at INT NULL,
                sources TEXT NULL,
                UNIQUE KEY uq_endpoint (endpoint_hash),
                KEY idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return;
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT NOT NULL, endpoint_hash CHAR(64) NOT NULL UNIQUE,
            endpoint TEXT NOT NULL, p256dh VARCHAR(120) NOT NULL, auth VARCHAR(40) NOT NULL,
            user_agent VARCHAR(255) NOT NULL DEFAULT '', created_at INT NOT NULL, last_ok_at INT NULL, sources TEXT NULL)");
    }

    /** Kolumna sources (wybór sklepów/źródeł) w tabeli sprzed tej zmiany. */
    private static function ensureSourcesColumn(PDO $pdo): void
    {
        try {
            $pdo->query('SELECT sources FROM push_subscriptions LIMIT 1');
        } catch (\PDOException) {
            $pdo->exec('ALTER TABLE push_subscriptions ADD COLUMN sources TEXT NULL');
        }
    }

    /** Testy: zamiast HTTP woła $sender(url, body, headers) i bierze jego status. */
    public function withSender(\Closure $sender): self
    {
        $this->sender = $sender;
        return $this;
    }

    // ------------------------------------------------------------------ klucze VAPID

    /** Klucz publiczny VAPID (base64url, 65 bajtów) dla przeglądarki; tworzy parę kluczy przy pierwszym użyciu. */
    public function publicKey(?string $subject = null): string
    {
        $settings = new SettingsRepository($this->pdo);
        $pub = (string) $settings->get(self::PUBLIC_KEY, '');
        $priv = (string) $settings->get(self::PRIVATE_KEY, '');
        if ($pub === '' || $priv === '' || !str_contains($priv, 'PRIVATE KEY')) {
            $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
            if ($key === false || !openssl_pkey_export($key, $pem)) {
                throw new \RuntimeException('Nie udało się utworzyć kluczy powiadomień push (OpenSSL).');
            }
            $pub = self::b64u(self::rawPublic($key));
            $settings->setMany([self::PUBLIC_KEY => $pub, self::PRIVATE_KEY => (string) $pem]);
        }
        if ($subject !== null && $subject !== '' && (string) $settings->get(self::SUBJECT, '') !== $subject) {
            $settings->setMany([self::SUBJECT => $subject]);
        }
        return $pub;
    }

    // ------------------------------------------------------------------ subskrypcje

    /** Czy adres usługi push wygląda na prawdziwą usługę przeglądarki (https, znany host). */
    public static function allowedEndpoint(string $endpoint): bool
    {
        $p = parse_url($endpoint);
        if (!is_array($p) || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['port'])) {
            return false;
        }
        $host = strtolower((string) $p['host']);
        foreach (self::PUSH_HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }
        return false;
    }

    public function subscribe(int $userId, string $endpoint, string $p256dh, string $auth, string $userAgent = ''): void
    {
        if (!self::allowedEndpoint($endpoint) || strlen($endpoint) > 2000) {
            throw new \InvalidArgumentException('Nieobsługiwana usługa powiadomień push.');
        }
        if (strlen(self::b64uDecode($p256dh)) !== 65 || strlen(self::b64uDecode($auth)) !== 16) {
            throw new \InvalidArgumentException('Niepoprawne klucze subskrypcji push.');
        }
        self::migrate($this->pdo);
        self::ensureSourcesColumn($this->pdo);
        $hash = hash('sha256', $endpoint);
        // Wybór sklepów zostaje przy ponownym zapisie tego samego urządzenia (to samo konto).
        $st = $this->pdo->prepare('SELECT user_id, sources FROM push_subscriptions WHERE endpoint_hash = ?');
        $st->execute([$hash]);
        $old = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        $sources = $old !== null && (int) $old['user_id'] === $userId ? $old['sources'] : null;
        // DELETE + INSERT - działa też na SQLite; to samo urządzenie po zalogowaniu na inne konto przechodzi na nie.
        $this->pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint_hash = ?')->execute([$hash]);
        $this->pdo->prepare('INSERT INTO push_subscriptions (user_id, endpoint_hash, endpoint, p256dh, auth, user_agent, created_at, sources)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$userId, $hash, $endpoint, $p256dh, $auth, mb_substr($userAgent, 0, 255), time(), $sources]);
    }

    /**
     * Sklepy/źródła, z których to urządzenie chce powiadomienia: lista id kont integracji (0 = zamówienia ręczne
     * i bez źródła); null = wszystkie, także sklepy dodane później.
     * @return list<int>|null
     */
    public function sources(string $endpoint, int $userId): ?array
    {
        try {
            $st = $this->pdo->prepare('SELECT sources FROM push_subscriptions WHERE endpoint_hash = ? AND user_id = ?');
            $st->execute([hash('sha256', $endpoint), $userId]);
            return self::decodeSources($st->fetchColumn());
        } catch (\PDOException) {
            return null;
        }
    }

    /** @param list<int>|null $sources null = wszystkie @return bool false, gdy tego urządzenia nie ma na tym koncie */
    public function saveSources(string $endpoint, int $userId, ?array $sources): bool
    {
        self::ensureSourcesColumn($this->pdo);
        $json = $sources === null ? null : json_encode(array_values(array_unique(array_map('intval', $sources))));
        $st = $this->pdo->prepare('UPDATE push_subscriptions SET sources = ? WHERE endpoint_hash = ? AND user_id = ?');
        $st->execute([$json, hash('sha256', $endpoint), $userId]);
        return $st->rowCount() > 0;
    }

    /** @return list<int>|null */
    private static function decodeSources(mixed $raw): ?array
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $list = json_decode($raw, true);
        return is_array($list) ? array_values(array_map('intval', $list)) : null;
    }

    /**
     * Do wyboru przy powiadomieniach: konta integracji, z których przychodzą zamówienia (sklepy, Allegro, TikTok…),
     * plus „Zamówienia ręczne” (id 0).
     * @return list<array{id:int,label:string}>
     */
    public function sourceOptions(): array
    {
        $labels = ['woocommerce' => 'WooCommerce', 'allegro' => 'Allegro', 'tiktokshop' => 'TikTok Shop'];
        $types = [];
        try {
            $types = array_keys(\Pase\Plugin\PluginRegistry::withCapability(\Pase\Plugin\Capability::ORDER_SOURCE));
        } catch (\Throwable) {
            $types = [];
        }
        if ($types === []) {
            $types = array_keys($labels);
        }
        $out = [];
        try {
            $ph = implode(',', array_fill(0, count($types), '?'));
            $st = $this->pdo->prepare("SELECT id, type, name FROM integration_accounts WHERE type IN ($ph) ORDER BY is_active DESC, type, name");
            $st->execute($types);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $type = (string) $r['type'];
                $typeLabel = $labels[$type] ?? (\Pase\Services\OrderChannelIcons::sourceName($type) ?? $type);
                $name = trim((string) $r['name']);
                $out[] = ['id' => (int) $r['id'], 'label' => $name !== '' && $name !== $typeLabel ? $typeLabel . ': ' . $name : $typeLabel];
            }
        } catch (\PDOException) {
            // baza bez kont integracji
        }
        $out[] = ['id' => 0, 'label' => 'Zamówienia ręczne'];
        return $out;
    }

    public function unsubscribe(string $endpoint, ?int $userId = null): void
    {
        try {
            $sql = 'DELETE FROM push_subscriptions WHERE endpoint_hash = ?' . ($userId !== null ? ' AND user_id = ?' : '');
            $this->pdo->prepare($sql)->execute($userId !== null ? [hash('sha256', $endpoint), $userId] : [hash('sha256', $endpoint)]);
        } catch (\PDOException) {
            // brak tabeli - nic do usunięcia
        }
    }

    /** @return list<array<string,mixed>> */
    private function subscriptions(?int $onlyUser = null, ?int $exceptUser = null): array
    {
        try {
            $sql = 'SELECT * FROM push_subscriptions WHERE 1=1';
            $args = [];
            if ($onlyUser !== null) { $sql .= ' AND user_id = ?'; $args[] = $onlyUser; }
            if ($exceptUser !== null) { $sql .= ' AND user_id <> ?'; $args[] = $exceptUser; }
            $st = $this->pdo->prepare($sql . ' ORDER BY id');
            $st->execute($args);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return []; // nikt jeszcze nie włączył powiadomień - tabeli nie ma
        }
    }

    // ------------------------------------------------------------------ wysyłka

    /**
     * Wysyła powiadomienie na urządzenia (wszystkich albo jednego użytkownika). Błąd jednego urządzenia nie
     * zatrzymuje reszty. @param array{title:string,body?:string,url?:string,tag?:string} $message
     * @return int liczba urządzeń, które przyjęły powiadomienie
     */
    public function send(array $message, ?int $onlyUser = null, ?int $exceptUser = null, ?int $sourceId = null): int
    {
        $subs = $this->subscriptions($onlyUser, $exceptUser);
        if ($sourceId !== null) {
            // Urządzenie z wybranymi sklepami dostaje tylko zamówienia z nich (null = wszystkie).
            $subs = array_values(array_filter($subs, static function (array $s) use ($sourceId): bool {
                $list = self::decodeSources($s['sources'] ?? null);
                return $list === null || in_array($sourceId, $list, true);
            }));
        }
        if ($subs === []) {
            return 0;
        }
        $settings = new SettingsRepository($this->pdo);
        $pem = (string) $settings->get(self::PRIVATE_KEY, '');
        $pub = (string) $settings->get(self::PUBLIC_KEY, '');
        $privateKey = $pem !== '' ? openssl_pkey_get_private($pem) : false;
        if ($privateKey === false || $pub === '') {
            Logger::warn('Push: brak kluczy VAPID - powiadomienia nie wysłane');
            return 0;
        }
        $subject = (string) $settings->get(self::SUBJECT, '');
        $payload = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // Usługi push przyjmują do ~4 KB - długa treść jest przycinana.
        if (strlen($payload) > 3000 && isset($message['body'])) {
            $message['body'] = mb_substr((string) $message['body'], 0, 300) . '…';
            $payload = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $ok = 0;
        foreach ($subs as $s) {
            try {
                $endpoint = (string) $s['endpoint'];
                if (!self::allowedEndpoint($endpoint)) {
                    continue;
                }
                $body = self::encrypt($payload, (string) $s['p256dh'], (string) $s['auth']);
                $p = parse_url($endpoint);
                $jwt = self::vapidJwt($p['scheme'] . '://' . $p['host'], $subject !== '' ? $subject : 'mailto:push@veless.pl', $privateKey);
                $headers = [
                    'Content-Type'     => 'application/octet-stream',
                    'Content-Encoding' => 'aes128gcm',
                    'TTL'              => '86400',
                    'Urgency'          => 'high',
                    'Authorization'    => 'vapid t=' . $jwt . ', k=' . $pub,
                ];
                if (!empty($message['tag'])) {
                    $headers['Topic'] = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) $message['tag']), 0, 32);
                }
                if ($this->sender !== null) {
                    $status = (int) (($this->sender)($endpoint, $body, $headers)['status'] ?? 0);
                    $respBody = '';
                } else {
                    $r = Http::request('POST', $endpoint, $headers, $body, 10);
                    $status = $r->status;
                    $respBody = $r->body;
                }
                if ($status >= 200 && $status < 300) {
                    $ok++;
                    $this->pdo->prepare('UPDATE push_subscriptions SET last_ok_at = ? WHERE id = ?')->execute([time(), (int) $s['id']]);
                } elseif ($status === 404 || $status === 410) {
                    // Urządzenie wyłączyło powiadomienia albo usunęło aplikację - subskrypcja nieaktualna.
                    $this->pdo->prepare('DELETE FROM push_subscriptions WHERE id = ?')->execute([(int) $s['id']]);
                    Logger::info('Push: usunięto wygasłą subskrypcję #' . $s['id']);
                } else {
                    Logger::warn('Push: usługa odrzuciła powiadomienie', ['subscription' => (int) $s['id'], 'status' => $status,
                        'response' => mb_substr($respBody, 0, 200)]);
                }
            } catch (\Throwable $e) {
                Logger::warn('Push: błąd wysyłki', ['subscription' => (int) $s['id'], 'error' => $e->getMessage()]);
            }
        }
        return $ok;
    }

    /**
     * Powiadomienie „Nowe zamówienie” - wołane raz na zamówienie przy zdarzeniu „Pobrano zamówienie”
     * (RuleEngine). Zamówienie dodane ręcznie w panelu nie trafia na urządzenia osoby, która je dodała.
     */
    public function notifyNewOrder(int $wooOrderId, array $payload): int
    {
        if ($this->subscriptions() === []) {
            return 0;
        }
        // Import starych zamówień (np. pierwsze pobranie historii) nie zasypuje telefonu powiadomieniami.
        $sourceId = 0;
        try {
            $st = $this->pdo->prepare('SELECT date_created, integration_id FROM woo_orders WHERE woo_order_id = ?');
            $st->execute([$wooOrderId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $created = (string) ($row['date_created'] ?? '');
            $sourceId = (int) ($row['integration_id'] ?? 0);
        } catch (\PDOException) {
            $created = '';
        }
        $createdTs = $created !== '' ? strtotime($created . (preg_match('/[zZ]|[+-]\d\d:?\d\d$/', $created) ? '' : ' UTC')) : false;
        if ($createdTs !== false && $createdTs < time() - self::MAX_ORDER_AGE) {
            return 0;
        }
        // Raz na zamówienie (ponowne zdarzenie po płatności, ponowny import itp.).
        try {
            $this->pdo->prepare('INSERT INTO automation_once (order_id, event_key, created_at) VALUES (?, ?, ?)')
                ->execute([$wooOrderId, 'push.new_order', gmdate('Y-m-d H:i:s')]);
        } catch (\PDOException $e) {
            if (str_contains(strtolower($e->getMessage()), 'no such table') || str_contains($e->getMessage(), '42S02')) {
                // starsza baza bez automation_once - wysyłamy bez blokady
            } else {
                return 0; // już wysłane
            }
        }
        $exceptUser = PHP_SAPI !== 'cli' && isset($_SESSION['pase_user_id']) ? (int) $_SESSION['pase_user_id'] : null;
        return $this->send(self::newOrderMessage($this->pdo, $wooOrderId, $payload), null, $exceptUser, $sourceId);
    }

    /** @return array{title:string,body:string,url:string,tag:string} */
    public static function newOrderMessage(PDO $pdo, int $wooOrderId, array $payload): array
    {
        $row = [];
        try {
            $st = $pdo->prepare('SELECT wo.order_number, wo.total, wo.currency, wo.customer_name, ia.type AS src_type, ia.name AS src_name
                FROM woo_orders wo LEFT JOIN integration_accounts ia ON ia.id = wo.integration_id WHERE wo.woo_order_id = ?');
            $st->execute([$wooOrderId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException) {
            try { // baza bez kolumny źródła
                $st = $pdo->prepare('SELECT order_number, total, currency, customer_name FROM woo_orders WHERE woo_order_id = ?');
                $st->execute([$wooOrderId]);
                $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            } catch (\PDOException) {
                $row = [];
            }
        }
        $number = (string) (($row['order_number'] ?? '') ?: ($payload['number'] ?? '') ?: $wooOrderId);
        $total = $row['total'] ?? ($payload['total'] ?? null);
        $currency = (string) (($row['currency'] ?? '') ?: ($payload['currency'] ?? 'PLN'));
        $customer = trim((string) ($row['customer_name'] ?? ''));
        if ($customer === '') {
            $customer = trim(($payload['billing']['first_name'] ?? '') . ' ' . ($payload['billing']['last_name'] ?? ''));
        }
        $labels = ['woocommerce' => 'Sklep', 'allegro' => 'Allegro', 'manual' => 'Ręczne', 'tiktokshop' => 'TikTok Shop'];
        $type = (string) (($row['src_type'] ?? '') ?: ($payload['created_via'] ?? ''));
        $source = (string) ($row['src_name'] ?? '');
        if ($source === '') {
            $source = $labels[$type] ?? '';
        }

        $parts = array_values(array_filter([
            $source,
            $total !== null && $total !== '' ? number_format((float) $total, 2, ',', ' ') . ' ' . ($currency === 'PLN' ? 'zł' : $currency) : '',
            $customer,
        ], static fn($v) => $v !== ''));
        $items = [];
        foreach (array_slice((array) ($payload['line_items'] ?? []), 0, 3) as $it) {
            $name = trim((string) ($it['name'] ?? ''));
            if ($name !== '') {
                $items[] = ((int) ($it['quantity'] ?? 1)) . '× ' . mb_substr($name, 0, 60);
            }
        }
        $more = count((array) ($payload['line_items'] ?? [])) - count($items);
        $body = implode(' · ', $parts);
        if ($items !== []) {
            $body .= ($body !== '' ? "\n" : '') . implode(', ', $items) . ($more > 0 ? ' +' . $more : '');
        }
        return [
            'title' => 'Nowe zamówienie #' . $number,
            'body'  => $body,
            'url'   => 'order_view.php?id=' . $wooOrderId,
            'tag'   => 'order-' . $wooOrderId,
        ];
    }

    // ------------------------------------------------------------------ kryptografia (RFC 8291 / 8292)

    /** Szyfruje treść dla jednego urządzenia (Content-Encoding: aes128gcm, jeden rekord). */
    public static function encrypt(string $plaintext, string $uaPublicB64, string $authB64, ?\OpenSSLAsymmetricKey $ephemeral = null, ?string $salt = null): string
    {
        $uaPublic = self::b64uDecode($uaPublicB64);
        $authSecret = self::b64uDecode($authB64);
        $ephemeral ??= openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($ephemeral === false) {
            throw new \RuntimeException('OpenSSL: brak klucza tymczasowego');
        }
        $asPublic = self::rawPublic($ephemeral);
        $uaKey = openssl_pkey_get_public(self::pemFromRaw($uaPublic));
        if ($uaKey === false) {
            throw new \RuntimeException('Niepoprawny klucz publiczny urządzenia');
        }
        $shared = openssl_pkey_derive($uaKey, $ephemeral, 32);
        if ($shared === false) {
            throw new \RuntimeException('OpenSSL: ECDH nie powiódł się');
        }
        $salt ??= random_bytes(16);
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $tag = '';
        $cipher = openssl_encrypt($plaintext . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new \RuntimeException('OpenSSL: szyfrowanie nie powiodło się');
        }
        return $salt . pack('N', 4096) . chr(strlen($asPublic)) . $asPublic . $cipher . $tag;
    }

    /** JWT ES256 dla nagłówka Authorization: vapid (ważny 12 h). */
    public static function vapidJwt(string $audience, string $subject, \OpenSSLAsymmetricKey $privateKey, ?int $now = null): string
    {
        $now ??= time();
        $input = self::b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']))
            . '.' . self::b64u(json_encode(['aud' => $audience, 'exp' => $now + 12 * 3600, 'sub' => $subject], JSON_UNESCAPED_SLASHES));
        if (!openssl_sign($input, $der, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('OpenSSL: podpis VAPID nie powiódł się');
        }
        return $input . '.' . self::b64u(self::derToRaw($der));
    }

    /** Podpis ECDSA z DER (SEQUENCE{INTEGER r, INTEGER s}) na 64 bajty r||s. */
    public static function derToRaw(string $der): string
    {
        $pos = 2;
        if ((ord($der[1]) & 0x80) !== 0) {
            $pos += ord($der[1]) & 0x7f;
        }
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$pos + 1]);
            $int = ltrim(substr($der, $pos + 2, $len), "\0");
            $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
            $pos += 2 + $len;
        }
        return $out;
    }

    /** Punkt publiczny klucza EC w postaci nieskompresowanej (0x04||X||Y, 65 bajtów). */
    public static function rawPublic(\OpenSSLAsymmetricKey $key): string
    {
        $d = openssl_pkey_get_details($key);
        if (!is_array($d) || !isset($d['ec']['x'], $d['ec']['y'])) {
            throw new \RuntimeException('OpenSSL: to nie jest klucz EC');
        }
        return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
    }

    public static function pemFromRaw(string $raw): string
    {
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode(hex2bin(self::P256_SPKI_PREFIX) . $raw), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    public static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $s): string
    {
        $d = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        return $d === false ? '' : $d;
    }
}
