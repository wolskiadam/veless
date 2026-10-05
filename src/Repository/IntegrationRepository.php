<?php
declare(strict_types=1);

namespace Pase\Repository;

use PDO;
use Pase\Support\SecretStore;

/**
 * Dostęp do tabeli `integrations` - tokeny i sekrety platform.
 */
final class IntegrationRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function find(string $platform): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM integrations WHERE platform_name = ? LIMIT 1');
        $stmt->execute([$platform]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? SecretStore::openRow('integrations', $row, false) : null;
    }

    public function getWebhookSecret(string $platform): ?string
    {
        $row = $this->find($platform);
        return $row['webhook_secret'] ?? null;
    }

    /**
     * Zapis/aktualizacja tokenów. UPSERT po unikalnym platform_name.
     */
    public function saveTokens(
        string $platform,
        string $accessToken,
        ?string $refreshToken,
        \DateTimeInterface $expiresAt
    ): void {
        $sql = 'INSERT INTO integrations (platform_name, access_token, refresh_token, expires_at)
                VALUES (:platform, :access, :refresh, :expires)
                ON DUPLICATE KEY UPDATE
                    access_token  = VALUES(access_token),
                    refresh_token = VALUES(refresh_token),
                    expires_at    = VALUES(expires_at)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':platform' => $platform,
            ':access'   => SecretStore::seal($accessToken, 'integrations.access_token'),
            ':refresh'  => SecretStore::seal($refreshToken, 'integrations.refresh_token'),
            ':expires'  => $expiresAt->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Integracje, których access_token wygasa w ciągu `$withinMinutes` minut.
     * Używane przez cron odświeżający tokeny (15 min przed wygaśnięciem).
     *
     * @return array<int,array<string,mixed>>
     */
    public function findExpiringSoon(int $withinMinutes): array
    {
        $sql = "SELECT * FROM integrations
                WHERE refresh_token IS NOT NULL
                  AND expires_at IS NOT NULL
                  AND expires_at <= (UTC_TIMESTAMP() + INTERVAL :mins MINUTE)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':mins', $withinMinutes, PDO::PARAM_INT);
        $stmt->execute();
        return array_map(static fn(array $r): array => SecretStore::openRow('integrations', $r, false), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
