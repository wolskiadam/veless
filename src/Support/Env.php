<?php
declare(strict_types=1);

namespace Pase\Support;

/**
 * Minimalny parser pliku .env - bez zewnętrznych zależności,
 * żeby działał na każdym shared hostingu bez Composera.
 */
final class Env
{
    private static array $vars = [];
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }
        if (!is_readable($path)) {
            throw new \RuntimeException("Brak pliku .env: {$path}");
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);
            // zdejmij ewentualne cudzysłowy
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'")) {
                $value = substr($value, 1, -1);
            }
            self::$vars[$key] = $value;
        }
        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::$vars[$key] ?? $default;
    }

    /** Pobiera wartość obowiązkową - rzuca wyjątek jeśli pusta. */
    public static function require(string $key): string
    {
        $val = self::$vars[$key] ?? null;
        if ($val === null || $val === '') {
            throw new \RuntimeException("Brak wymaganej zmiennej .env: {$key}");
        }
        return $val;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $val = self::$vars[$key] ?? null;
        if ($val === null) {
            return $default;
        }
        return in_array(strtolower($val), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $val = self::$vars[$key] ?? null;
        return $val === null || $val === '' ? $default : (int) $val;
    }
}
