<?php
declare(strict_types=1);

/**
 * Fabryka połączenia PDO (MySQL). Singleton - jedno połączenie na proces.
 * Zwraca skonfigurowane PDO w trybie wyjątków, z natywnymi prepared statements.
 *
 * Użycie:
 *   $config = require config/config.php;
 *   $pdo    = (require config/database.php)($config['db']);
 */

return static function (array $db): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $db['host'],
        $db['port'],
        $db['name'],
        $db['charset']
    );

    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // błędy jako wyjątki
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,         // wygodne tablice asocjacyjne
        PDO::ATTR_EMULATE_PREPARES   => false,                   // prawdziwe prepared statements
    ]);

    // Wspólny kontekst dla wtyczek (patrz Pase\Support\Runtime).
    \Pase\Support\Runtime::setPdo($pdo);

    // Auto-migracja: dotwórz brakujące tabele przy pierwszym połączeniu w procesie.
    // Tani guard w Migratorze sprawia, że gdy komplet tabel istnieje, koszt ~zero.
    // Nie przerywaj aplikacji, jeśli użytkownik bazy nie ma uprawnień do CREATE -
    // wtedy pozostaje ręczny import sql/schema.sql.
    try {
        (new \Pase\Support\Migrator($pdo))->migrate();
    } catch (\Throwable $e) {
        \Pase\Support\Logger::warn('Auto-migracja nie powiodła się: ' . $e->getMessage());
    }

    // Mapowania statusów ustawione w panelu (Konfiguracja → Statusy zamówień).
    // Rejestrujemy sam loader, nie dane: zapytanie poleci dopiero wtedy, gdy coś
    // faktycznie będzie mapować status, więc samo wyświetlenie strony nic nie kosztuje.
    \Pase\Domain\OrderStatus::useMapLoader(
        static fn(): array => (new \Pase\Repository\StatusMapRepository($pdo))->all()
    );

    return $pdo;
};
