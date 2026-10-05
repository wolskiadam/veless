<?php
declare(strict_types=1);

namespace Pase\Repository;

use PDO;
use Pase\Support\SecretStore;

/**
 * Dostęp do tabeli integration_accounts - WIELE integracji, także kilka tego
 * samego typu (np. kilka sklepów WooCommerce podpiętych do jednego PASE).
 *
 * `config` to JSON z kluczami zależnymi od typu integracji:
 *   woocommerce: base_url, consumer_key, consumer_secret, sync_status
 *   wfirma:      base_url, access_key, secret_key, app_key, company_id
 *   allegro:     client_id, client_secret, redirect_uri, env
 */
final class IntegrationAccountRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<int,array<string,mixed>> wszystkie integracje (z odkodowanym config) */
    public function all(): array
    {
        $rows = $this->pdo->query('SELECT * FROM integration_accounts ORDER BY type, id')
            ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r = $this->decode($r);
        }
        return $rows;
    }

    /** Aktywne integracje danego typu (np. wszystkie sklepy Woo do importu/sync). */
    public function activeByType(string $type): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM integration_accounts WHERE type = ? AND is_active = 1 ORDER BY id'
        );
        $stmt->execute([$type]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r = $this->decode($r);
        }
        return $rows;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM integration_accounts WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return null;
        }
        return $this->decode($r);
    }

    /** Wiersz z bazy -> config jako tablica, sekrety odszyfrowane (SecretStore). */
    private function decode(array $r): array
    {
        $r = SecretStore::openRow('integration_accounts', $r, false);
        $r['config'] = SecretStore::openConfig(json_decode($r['config'] ?? '{}', true) ?: [], false);
        return $r;
    }

    /** Pierwsza aktywna integracja danego typu (wygodne np. dla wFirma = jedna). */
    public function firstActive(string $type): ?array
    {
        $list = $this->activeByType($type);
        return $list[0] ?? null;
    }

    public function create(string $type, string $name, array $config, ?string $webhookSecret = null): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO integration_accounts (type, name, is_active, config, webhook_secret)
             VALUES (:t, :n, 1, :c, :s)'
        );
        $stmt->execute([
            ':t' => $type, ':n' => $name,
            ':c' => json_encode(SecretStore::sealConfig($config), JSON_UNESCAPED_UNICODE),
            ':s' => SecretStore::seal($webhookSecret, 'integration_accounts.webhook_secret'),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $name, array $config, ?string $webhookSecret, bool $isActive): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE integration_accounts
             SET name = :n, config = :c, webhook_secret = :s, is_active = :a
             WHERE id = :id'
        );
        $stmt->execute([
            ':n' => $name,
            ':c' => json_encode(SecretStore::sealConfig($config), JSON_UNESCAPED_UNICODE),
            ':s' => SecretStore::seal($webhookSecret, 'integration_accounts.webhook_secret'),
            ':a' => $isActive ? 1 : 0,
            ':id' => $id,
        ]);
    }

    /** Aktualizacja tylko config (np. zachowanie sekretów przy częściowej edycji). */
    public function updateConfig(int $id, array $config): void
    {
        $this->pdo->prepare('UPDATE integration_accounts SET config = ? WHERE id = ?')
            ->execute([json_encode(SecretStore::sealConfig($config), JSON_UNESCAPED_UNICODE), $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM integration_accounts WHERE id = ?')->execute([$id]);
    }

    public function saveTokens(int $id, string $accessToken, ?string $refreshToken, \DateTimeInterface $expiresAt): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE integration_accounts SET access_token = :a, refresh_token = :r, expires_at = :e WHERE id = :id'
        );
        $stmt->execute([
            ':a' => SecretStore::seal($accessToken, 'integration_accounts.access_token'),
            ':r' => SecretStore::seal($refreshToken, 'integration_accounts.refresh_token'),
            ':e' => $expiresAt->format('Y-m-d H:i:s'), ':id' => $id,
        ]);
    }
}
