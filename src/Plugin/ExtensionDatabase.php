<?php
declare(strict_types=1);

namespace Pase\Plugin;

use Pase\Support\Env;
use PDO;

/**
 * Osobna baza danych dla rozszerzenia, włączana przez administratora w .env:
 *
 *   EXT_KSIEGOWOSC_DB_DSN=mysql:host=localhost;dbname=ksiegowosc;charset=utf8mb4
 *   EXT_KSIEGOWOSC_DB_USER=...
 *   EXT_KSIEGOWOSC_DB_PASS=...
 *
 * Rozszerzenie nie czyta .env samo (sandbox wtyczek tego zabrania) - prosi rdzeń o połączenie. Bez wpisu w .env
 * dostaje null i używa bazy CRM. Dzięki temu dane modułu (np. księgowości) da się trzymać osobno i kiedyś wynieść
 * na inny serwer bez zmian w kodzie.
 */
final class ExtensionDatabase
{
    /** @var array<string,PDO> */
    private static array $connections = [];

    public static function configured(string $type): bool
    {
        return trim((string) Env::get(self::key($type) . '_DSN', '')) !== '';
    }

    public static function connection(string $type): ?PDO
    {
        if (!self::configured($type)) {
            return null;
        }
        return self::$connections[$type] ??= new PDO((string) Env::get(self::key($type) . '_DSN'), Env::get(self::key($type) . '_USER'),
            Env::get(self::key($type) . '_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }

    public static function reset(): void
    {
        self::$connections = [];
    }

    private static function key(string $type): string
    {
        if (!preg_match('/^[a-z0-9_]+$/', $type)) {
            throw new \InvalidArgumentException('Niepoprawna nazwa rozszerzenia: ' . $type);
        }
        return 'EXT_' . strtoupper($type) . '_DB';
    }
}
