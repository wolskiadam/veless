<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use Pase\Repository\IntegrationRepository;
use Pase\Support\Logger;
use PasePlugin\Allegro\AllegroPlugin;

/**
 * Odświeżanie tokenów OAuth, zanim wygasną.
 *
 * Token dostępowy Allegro żyje 12 godzin. Gdy wygaśnie, a nikt go nie odnowi,
 * KAŻDE odwołanie do API kończy się kodem 401: zamówienia przestają przychodzić,
 * stany nie idą do ofert, a w panelu Powiązania świeci błąd. Dlatego odświeżamy
 * z marginesem - każdy token, któremu zostało mniej niż 15 minut.
 *
 * Logika mieszka tutaj, bo wołają ją dwa miejsca: Scheduler (przy przebiegu
 * workera, co domyślnie oznacza jeden wpis w cronie) i cli/refresh_tokens.php
 * (osobny wpis, zostawiony dla zgodności ze starszymi instalacjami).
 */
final class TokenRefresher
{
    /** Ile minut przed wygaśnięciem odnawiamy token. */
    public const MARGIN_MINUTES = 15;

    /** @param array<string,mixed> $allegroConfig sekcja 'allegro' z config/config.php */
    public function __construct(
        private readonly PDO $pdo,
        private readonly array $allegroConfig
    ) {}

    /**
     * Odświeża wszystkie tokeny bliskie wygaśnięcia.
     * @return int ile tokenów udało się odświeżyć
     */
    public function run(): int
    {
        try {
            $expiring = (new IntegrationRepository($this->pdo))->findExpiringSoon(self::MARGIN_MINUTES);
        } catch (\Throwable $e) {
            Logger::warn('refresh_tokens: nie mogę odczytać integracji: ' . $e->getMessage());
            return 0;
        }

        if ($expiring === []) {
            return 0;
        }

        $refreshed = 0;
        foreach ($expiring as $integration) {
            $platform     = (string) ($integration['platform_name'] ?? '');
            $refreshToken = $integration['refresh_token'] ?? null;

            if ($refreshToken === null || $refreshToken === '') {
                Logger::warn("refresh_tokens: {$platform} bez refresh_token - pomijam");
                continue;
            }

            // Na razie tylko Allegro ma OAuth refresh. Woo/wFirma używają stałych kluczy.
            if ($platform !== 'allegro' || $this->allegroConfig === []) {
                continue;
            }

            Logger::info("refresh_tokens: odświeżam {$platform} (wygasa {$integration['expires_at']})");
            $ok = AllegroPlugin::makeClient($this->pdo, $this->allegroConfig)
                ->refreshAccessToken((string) $refreshToken);

            Logger::info("refresh_tokens: {$platform} -> " . ($ok ? 'OK' : 'FAIL'));
            if ($ok) {
                $refreshed++;
            }
        }

        return $refreshed;
    }
}
