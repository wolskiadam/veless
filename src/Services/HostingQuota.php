<?php
declare(strict_types=1);

namespace Pase\Services;

use Pase\Repository\SettingsRepository;
use Pase\Support\Http;

/**
 * Miejsce na KONCIE hostingu (limit pakietu), a nie na fizycznym dysku serwera.
 *
 * disk_free_space() na hostingu współdzielonym pokazuje wolne miejsce całego dysku serwera
 * (setki GB, wspólne dla wielu klientów) - nie mówi nic o limicie konta. Dlatego:
 *  1. Automatycznie: DirectAdmin (panel hostingu) - CMD_API_SHOW_USER_USAGE (zajęte) i
 *     CMD_API_SHOW_USER_CONFIG (limit), logowanie loginem + kluczem logowania (Login Key)
 *     ograniczonym tylko do tych dwóch poleceń. Wynik pamiętany 15 minut.
 *  2. Ręcznie (gdy brak klucza albo panel nie odpowiada): limit wpisany w GB; zajętość całego
 *     konta nie jest wtedy znana - widać tylko, ile z limitu zajmuje sam CRM.
 *
 * DirectAdmin podaje wartości w MB; „unlimited” = bez limitu.
 */
final class HostingQuota
{
    public const S_URL = 'DA_URL';
    public const S_USER = 'DA_USER';
    public const S_KEY = 'DA_LOGIN_KEY';
    public const S_MANUAL_GB = 'DISK_QUOTA_GB';
    private const CACHE_TTL = 900;

    /** @var callable(string,string,array<string,string>):array{status:int,body:string} */
    private $fetch;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly string $cacheFile,
        ?callable $fetch = null,
    ) {
        $this->fetch = $fetch ?? static function (string $method, string $url, array $headers): array {
            $r = Http::request($method, $url, $headers, null, 8);
            return ['status' => $r->status, 'body' => $r->body];
        };
    }

    public function configured(): bool
    {
        return $this->settings->get(self::S_URL) !== null && $this->settings->get(self::S_USER) !== null && $this->settings->get(self::S_KEY) !== null;
    }

    /**
     * @return array{source:?string,used:?float,limit:?float,db:?float,error:?string,checked_at:int}
     *   used/limit/db w bajtach; limit null = brak limitu/nieznany; source: directadmin|manual|null
     */
    public function status(bool $refresh = false): array
    {
        $manualGb = (float) str_replace(',', '.', (string) ($this->settings->get(self::S_MANUAL_GB, '0') ?? '0'));
        $manual = ['source' => $manualGb > 0 ? 'manual' : null, 'used' => null, 'limit' => $manualGb > 0 ? $manualGb * 1024 ** 3 : null,
            'db' => null, 'error' => null, 'checked_at' => time()];
        if (!$this->configured()) {
            return $manual;
        }
        $fingerprint = sha1((string) $this->settings->get(self::S_URL) . '|' . $this->settings->get(self::S_USER) . '|' . $this->settings->get(self::S_KEY));
        $cache = is_file($this->cacheFile) ? json_decode((string) @file_get_contents($this->cacheFile), true) : null;
        if (!$refresh && is_array($cache) && ($cache['fp'] ?? '') === $fingerprint && ($cache['data']['checked_at'] ?? 0) > time() - self::CACHE_TTL) {
            return $cache['data'];
        }
        try {
            $usage  = $this->call('CMD_API_SHOW_USER_USAGE');
            $config = $this->call('CMD_API_SHOW_USER_CONFIG');
            $used = self::mb($usage['quota'] ?? null);
            if ($used === null) { throw new \RuntimeException('Panel nie zwrócił zajętości konta (brak pola „quota”).'); }
            $limitRaw = $config['quota'] ?? null;
            $limit = self::mb($limitRaw);
            $data = ['source' => 'directadmin', 'used' => $used, 'limit' => $limit ?? $manual['limit'],
                'db' => self::mb($usage['db_quota'] ?? null), 'error' => null, 'checked_at' => time()];
        } catch (\Throwable $e) {
            $data = $manual;
            $data['error'] = $e->getMessage();
        }
        @file_put_contents($this->cacheFile, json_encode(['fp' => $fingerprint, 'data' => $data]), LOCK_EX);
        return $data;
    }

    /** @return array<string,mixed> */
    private function call(string $command): array
    {
        $base = rtrim((string) $this->settings->get(self::S_URL), '/');
        if (!preg_match('#^https?://#i', $base)) { throw new \RuntimeException('Adres panelu musi zaczynać się od https://'); }
        $auth = base64_encode($this->settings->get(self::S_USER) . ':' . $this->settings->get(self::S_KEY));
        $r = ($this->fetch)('GET', $base . '/' . $command . '?json=yes', ['Authorization' => 'Basic ' . $auth, 'Accept' => 'application/json']);
        if ($r['status'] === 401 || $r['status'] === 403) { throw new \RuntimeException('Panel odrzucił login lub klucz logowania (HTTP ' . $r['status'] . ').'); }
        if ($r['status'] < 200 || $r['status'] >= 300) {
            throw new \RuntimeException($r['status'] === 0 ? 'Brak połączenia z panelem: ' . $r['body'] : 'Panel odpowiedział błędem HTTP ' . $r['status'] . '.');
        }
        $body = trim($r['body']);
        $data = json_decode($body, true);
        if (!is_array($data)) {
            parse_str($body, $data); // starszy format DirectAdmin: klucz=wartość&...
        }
        if (!is_array($data) || $data === [] || isset($data['error']) && (string) $data['error'] !== '0') {
            $detail = is_array($data) ? (string) ($data['text'] ?? $data['details'] ?? '') : '';
            throw new \RuntimeException('Panel nie zwrócił danych' . ($detail !== '' ? ': ' . $detail : '') . '. Sprawdź, czy klucz ma uprawnienie do ' . $command . '.');
        }
        return $data;
    }

    /** MB z DirectAdmin → bajty; „unlimited”/puste → null. */
    private static function mb(mixed $v): ?float
    {
        if (is_array($v)) { $v = $v['usage'] ?? $v['value'] ?? null; }
        if ($v === null || $v === '' || !is_numeric(str_replace(',', '.', (string) $v))) { return null; }
        return (float) str_replace(',', '.', (string) $v) * 1024 * 1024;
    }
}
