<?php
declare(strict_types=1);

namespace Pase\Support;

use PDO;

/**
 * Wspólny kontekst procesu dla wtyczek: połączenie z bazą i konfiguracja aplikacji.
 *
 * Wtyczki dostają od rejestru tylko config swojego konta (withConfig). Gdy potrzebują
 * czegoś więcej - np. „Wysyłam z Allegro" korzysta z tokenów OAuth konta Allegro
 * zapisanych w bazie - sięgają tutaj, zamiast ponownie ładować config/config.php.
 * Ustawiane w config/config.php (konfiguracja) i config/database.php (PDO).
 */
final class Runtime
{
    private static ?PDO $pdo = null;
    /** @var array<string,mixed> */
    private static array $config = [];

    public static function setPdo(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    /** @param array<string,mixed> $config */
    public static function setConfig(array $config): void
    {
        self::$config = $config;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            throw new \RuntimeException('Brak połączenia z bazą (Runtime::setPdo nie zostało wywołane).');
        }
        return self::$pdo;
    }

    /** Sekcja konfiguracji (np. 'allegro') albo całość, gdy $section = null. */
    public static function config(?string $section = null): array
    {
        if ($section === null) {
            return self::$config;
        }
        $v = self::$config[$section] ?? [];
        return is_array($v) ? $v : [];
    }
}
