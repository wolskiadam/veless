<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use Pase\Repository\SettingsRepository;

/**
 * Niski stan w magazynie CRM.
 *
 * Każdy produkt może mieć własny minimalny stan (products.min_stock). Produkt bez własnego minimum
 * korzysta z progu domyślnego z ustawień (LOW_STOCK_DEFAULT, domyślnie 5 - tyle było na sztywno
 * na liście produktów). Pusty próg domyślny = alarm tylko dla produktów z własnym minimum.
 * Niski stan = stan w CRM ustawiony i nie większy niż minimum. Produkty bez stanu pomijamy.
 * Gdy pole „Stan faktyczny” jest włączone (ActualStock::enabled), liczymy z niego, jeśli jest wpisany;
 * inaczej ze stanu w CRM (pase_stock). $useActual w metodach statycznych = to ustawienie.
 * min_stock = -1 (OFF) = „nie pilnuj stanu” (np. produkt wycofany) - nigdy nie jest niski.
 * Tylko informacja: nic tu nie zamawia towaru ani nie zmienia ofert.
 */
final class LowStock
{
    public const SETTING = 'LOW_STOCK_DEFAULT';
    public const FALLBACK = 5;
    public const OFF = -1;

    public function __construct(private readonly PDO $pdo) {}

    public static function migrate(PDO $pdo): void
    {
        if (!self::columnExists($pdo, 'products', 'min_stock')) {
            $pdo->exec('ALTER TABLE products ADD COLUMN min_stock INT NULL');
        }
        ActualStock::migrate($pdo);   // products.actual_stock - z niego liczymy niski stan
    }

    /** Czy alerty liczą ze stanu faktycznego (pole włączone w ustawieniach). */
    public function usesActualStock(): bool
    {
        return ActualStock::enabled($this->pdo);
    }

    /** Stan, z którego liczymy niski stan (SQL): faktyczny, a gdy pusty - stan w CRM. */
    public static function stockSql(bool $useActual): string
    {
        return $useActual ? 'COALESCE(actual_stock, pase_stock)' : 'pase_stock';
    }

    /** Stan do alertów: faktyczny (gdy włączony i wpisany), inaczej stan w CRM; null = brak stanu. */
    public static function stockFor(array $product, bool $useActual = false): ?int
    {
        foreach ($useActual ? ['actual_stock', 'pase_stock'] : ['pase_stock'] as $k) {
            if (isset($product[$k]) && $product[$k] !== '') {
                return (int) $product[$k];
            }
        }
        return null;
    }

    /** Próg domyślny albo null, gdy wyłączony (alarm tylko dla produktów z własnym minimum). */
    public function defaultThreshold(): ?int
    {
        // SettingsRepository::get() traktuje pusty tekst jak brak ustawienia, więc „wyłączony” to 'off'.
        $v = trim((string) (new SettingsRepository($this->pdo))->get(self::SETTING, ''));
        if ($v === '') {
            return self::FALLBACK;
        }
        return preg_match('/^\d+$/', $v) ? (int) $v : null;
    }

    public function saveDefaultThreshold(?int $value): void
    {
        (new SettingsRepository($this->pdo))->setMany([self::SETTING => $value === null ? 'off' : (string) max(0, $value)]);
    }

    /** Minimum dla wiersza produktu: własne albo domyślne. */
    public static function minFor(array $product, ?int $default): ?int
    {
        return isset($product['min_stock']) && $product['min_stock'] !== '' ? (int) $product['min_stock'] : $default;
    }

    public static function isLow(array $product, ?int $default, bool $useActual = false): bool
    {
        $min = self::minFor($product, $default);
        $stock = self::stockFor($product, $useActual);
        return $min !== null && $min >= 0 && $stock !== null && $stock <= $min;
    }

    /**
     * Warunek SQL „niski stan” (dla ProductRepository::stockCondition). $default = próg domyślny.
     * @param array<string,mixed> $params
     */
    public static function condition(?int $default, array &$params, bool $useActual = false): string
    {
        $s = self::stockSql($useActual);
        if ($default === null) {
            return "{$s} IS NOT NULL AND min_stock IS NOT NULL AND min_stock >= 0 AND {$s} <= min_stock";
        }
        // Próg jako liczba w SQL (to int): parametr tekstowy porównany z COALESCE(...) w SQLite zawsze wygrywa.
        $default = (int) $default;
        return "{$s} IS NOT NULL AND (min_stock IS NULL OR min_stock >= 0) AND {$s} <= COALESCE(min_stock, {$default})";
    }

    /** null = próg domyślny, OFF = nie pilnuj stanu, liczba ≥ 0 = własne minimum. */
    public function setMin(int $productId, ?int $min): void
    {
        $st = $this->pdo->prepare('UPDATE products SET min_stock = ? WHERE id = ?');
        $st->execute([$min === null ? null : ($min === self::OFF ? self::OFF : max(0, $min)), $productId]);
    }

    /**
     * Produkty z niskim stanem, od najniższego stanu.
     * @return array{count:int,rows:array<int,array<string,mixed>>}
     */
    public function list(int $limit = 8): array
    {
        $params = [];
        $useActual = $this->usesActualStock();
        $where = self::condition($this->defaultThreshold(), $params, $useActual);
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM products WHERE {$where}");
        $st->execute($params);
        $count = (int) $st->fetchColumn();
        $st = $this->pdo->prepare('SELECT id, sku, name, pase_stock, actual_stock, min_stock FROM products WHERE ' . $where
            . ' ORDER BY ' . self::stockSql($useActual) . ' ASC, sku ASC LIMIT ' . max(1, $limit));
        $st->execute($params);
        return ['count' => $count, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)];
    }

    /**
     * „Nie pilnuj” dla produktów bez stanu (≤ 0) i bez własnego minimum - hurtem, np. wycofane.
     * Zwraca liczbę zmienionych produktów.
     */
    public function untrackSoldOut(): int
    {
        $s = self::stockSql($this->usesActualStock());
        return (int) $this->pdo->exec('UPDATE products SET min_stock = ' . self::OFF
            . " WHERE min_stock IS NULL AND {$s} IS NOT NULL AND {$s} <= 0");
    }

    /**
     * Wszystkie produkty z niskim stanem (id, sku, nazwa, stan, minimum), od najniższego stanu.
     * @return array<int,array<string,mixed>>
     */
    public function rows(): array
    {
        $params = [];
        $useActual = $this->usesActualStock();
        $st = $this->pdo->prepare('SELECT id, sku, name, pase_stock, actual_stock, min_stock FROM products WHERE '
            . self::condition($this->defaultThreshold(), $params, $useActual) . ' ORDER BY ' . self::stockSql($useActual) . ' ASC, sku ASC');
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                foreach ($pdo->query("PRAGMA table_info({$table})") as $c) {
                    if ($c['name'] === $column) {
                        return true;
                    }
                }
                return false;
            }
            // information_schema, nie „SHOW COLUMNS … LIKE ?” (natywne prepared statements, patrz WarehouseLocations).
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
            $stmt->execute([$table, $column]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (\PDOException) {
            return false;
        }
    }
}
