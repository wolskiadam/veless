<?php
declare(strict_types=1);

namespace Pase\Support;

use PDO;
use Pase\Plugin\PluginRegistry;
use Pase\Repository\IntegrationAccountRepository;

/**
 * Odczyt stanu połączeń dla rozszerzenia Kontrola stanu (extensions/kontrola). Tokeny, klucze i hasła SMTP zostają
 * w rdzeniu: rozszerzenie dostaje listę kont bez konfiguracji, wynik testu połączenia, oferty Allegro i stany ze sklepu
 * (tylko odczyt) oraz może wysłać mail alarmowy przez skrzynkę CRM. Zapytania o konta są w sandboxie wtyczek na liście
 * odczytów rdzenia (docker/sandbox/report.php).
 */
final class HealthBridge
{
    public const ACCOUNTS_SQL = "SELECT id, type, name, expires_at, CASE WHEN refresh_token IS NULL OR refresh_token = '' THEN 0 ELSE 1 END AS refreshable
        FROM integration_accounts WHERE is_active = 1 ORDER BY type, id";
    public const ALLEGRO_SQL = "SELECT expires_at, CASE WHEN access_token IS NULL OR access_token = '' THEN 0 ELSE 1 END AS connected
        FROM integrations WHERE platform_name = 'allegro' LIMIT 1";

    /** Aktywne konta integracji bez konfiguracji i kluczy. @return list<array{id:int,type:string,name:string,expires_at:?string,refreshable:bool}> */
    public static function accounts(PDO $pdo): array
    {
        try {
            $rows = $pdo->query(self::ACCOUNTS_SQL)->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return [];
        }
        return array_map(static fn(array $r): array => ['id' => (int) $r['id'], 'type' => (string) $r['type'], 'name' => (string) $r['name'],
            'expires_at' => $r['expires_at'] !== null ? (string) $r['expires_at'] : null, 'refreshable' => (int) $r['refreshable'] === 1], $rows);
    }

    /** Token Allegro: czy jest i do kiedy ważny (bez samego tokenu). @return array{connected:bool,expires_at:?string}|null */
    public static function allegroToken(PDO $pdo): ?array
    {
        try {
            $r = $pdo->query(self::ALLEGRO_SQL)->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return null;
        }
        return $r ? ['connected' => (int) $r['connected'] === 1, 'expires_at' => $r['expires_at'] !== null ? (string) $r['expires_at'] : null] : null;
    }

    /** Ten sam test co „Testuj połączenie” w Konfiguracja → Integracje. @return array{ok:bool,message:string}|null */
    public static function testAccount(PDO $pdo, int $id): ?array
    {
        $acc = (new IntegrationAccountRepository($pdo))->find($id);
        if ($acc === null) {
            return null;
        }
        $type = (string) $acc['type'];
        if ($type === 'vonhalsky' && \Pase\Services\Vonhalsky::available()) {
            \Pase\Services\Vonhalsky::migrate($pdo);
            $r = (new \Pase\Services\Vonhalsky($pdo))->test($acc);
        } elseif ($type === 'tiktokshop' && \Pase\Services\TiktokShop::available()) {
            \Pase\Services\TiktokShop::migrate($pdo);
            $r = (new \Pase\Services\TiktokShop($pdo))->test($acc);
        } else {
            $plugin = PluginRegistry::forAccount($type, is_array($acc['config'] ?? null) ? $acc['config'] : []);
            if ($plugin === null) {
                return ['ok' => false, 'message' => 'Brak zainstalowanej wtyczki „' . $type . '”.'];
            }
            $r = $plugin->testConnection();
        }
        return ['ok' => (bool) ($r['ok'] ?? false), 'message' => (string) ($r['message'] ?? '')];
    }

    /**
     * Allegro tylko do odczytu: connected(), checkConnection() (GET /me) i listOffers() - bez dostępu do tokenu.
     * null = brak aplikacji Allegro w konfiguracji.
     */
    public static function allegro(PDO $pdo): ?object
    {
        $cfg = Runtime::config('allegro');
        if (trim((string) ($cfg['client_id'] ?? '')) === '' || !class_exists(\PasePlugin\Allegro\AllegroPlugin::class)) {
            return null;
        }
        $client = \PasePlugin\Allegro\AllegroPlugin::makeClient($pdo, $cfg);
        return new class($client) {
            public function __construct(private readonly object $client) {}
            public function connected(): bool { return $this->client->bearerToken() !== null; }
            /** @return array{0:bool,1:string} */
            public function checkConnection(): array { return $this->client->checkConnection(); }
            public function listOffers(array $filters): array { return $this->client->listOffers($filters); }
        };
    }

    /** Sklep WooCommerce tylko do odczytu produktów: fetchProductsByIds(), fetchVariations(). */
    public static function woo(PDO $pdo, int $accountId): object
    {
        $acc = (new IntegrationAccountRepository($pdo))->find($accountId);
        $plugin = $acc !== null ? PluginRegistry::forAccount('woocommerce', is_array($acc['config'] ?? null) ? $acc['config'] : []) : null;
        if ($plugin === null || !method_exists($plugin, 'client')) {
            throw new \RuntimeException('Brak wtyczki WooCommerce albo konta sklepu.');
        }
        return new class($plugin->client()) {
            public function __construct(private readonly object $client) {}
            public function fetchProductsByIds(array $ids): array { return $this->client->fetchProductsByIds($ids); }
            public function fetchVariations(int $parentId, int $page = 1, int $perPage = 100): array { return $this->client->fetchVariations($parentId, $page, $perPage); }
        };
    }

    /** Śledzenie przesyłek (checkOne) z konfiguracją Allegro z rdzenia. */
    public static function tracker(PDO $pdo): object
    {
        $cfg = Runtime::config('allegro');
        return new \Pase\Services\ShipmentTracking($pdo, \PasePlugin\Allegro\AllegroPlugin::makeClient($pdo, $cfg), $cfg);
    }

    /** Mail alarmowy przez skrzynkę ustawioną w CRM. @return bool wysłano */
    public static function sendMail(PDO $pdo, string $to, string $subject, string $html): bool
    {
        $mailer = new \Pase\Services\Mailer((new \Pase\Services\MailAccounts($pdo))->mailerConfig(null));
        return (bool) ($mailer->send($to, $subject, $html, ['type' => 'ops_alert'])[0] ?? false);
    }
}
