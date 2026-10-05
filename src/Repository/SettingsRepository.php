<?php
declare(strict_types=1);

namespace Pase\Repository;

use PDO;
use Pase\Support\SecretStore;

/**
 * Ustawienia edytowalne z panelu (tabela settings, klucz-wartość).
 * Klucze API zapisywane tu mają pierwszeństwo nad .env (patrz config/config.php).
 *
 * Odporne na brak tabeli (np. zanim auto-migracja ją utworzy) - metody odczytu
 * zwracają wtedy puste wartości zamiast rzucać wyjątkiem.
 */
final class SettingsRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<string,string> wszystkie ustawienia jako mapa klucz=>wartość */
    public function all(): array
    {
        try {
            $rows = $this->pdo->query('SELECT setting_key, setting_value FROM settings')
                ->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            foreach ($rows as $k => $v) {
                $rows[$k] = SecretStore::openSetting((string) $k, $v, false);
            }
            return $rows;
        } catch (\PDOException $e) {
            return []; // tabela jeszcze nie istnieje
        }
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $all = $this->all();
        $val = $all[$key] ?? null;
        return ($val === null || $val === '') ? $default : $val;
    }

    /**
     * Zapisuje zestaw ustawień (UPSERT). Pomija klucze o wartości null
     * (pozwala zapisać tylko część pól bez kasowania reszty).
     *
     * @param array<string,?string> $values
     */
    public function setMany(array $values): void
    {
        // MySQL w produkcji; SQLite tylko w testach (inna składnia UPSERT).
        $stmt = $this->pdo->prepare($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)
               ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value'
            : 'INSERT INTO settings (setting_key, setting_value)
             VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        foreach ($values as $key => $value) {
            if ($value === null) {
                continue;
            }
            $stmt->execute([':k' => $key, ':v' => SecretStore::sealSetting((string) $key, $value)]);
        }
    }
}
