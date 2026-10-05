<?php
declare(strict_types=1);

namespace Pase\Plugin;

use Pase\Plugin\Contract\ExtensionPlugin;

/**
 * Wygodna baza dla rozszerzeń: ustawienia z formularza (setting()), katalog wtyczki (path())
 * i baza danych (pdo()).
 */
abstract class AbstractExtension implements ExtensionPlugin
{
    /** Wartość pola ustawień (manifest()->fields) z domyślną. */
    protected function setting(string $key, mixed $default = null): mixed
    {
        $all = ExtensionSettings::get($this->manifest()->type);
        return array_key_exists($key, $all) && $all[$key] !== '' ? $all[$key] : $default;
    }

    /** Ścieżka do pliku w katalogu rozszerzenia (np. assets/packing.js). */
    protected function path(string $file = ''): string
    {
        $dir = dirname((new \ReflectionClass($this))->getFileName());
        return $file === '' ? $dir : $dir . '/' . ltrim($file, '/');
    }

    protected function pdo(): \PDO
    {
        return \Pase\Support\Runtime::pdo();
    }
}
