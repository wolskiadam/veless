<?php
declare(strict_types=1);

namespace Pase\Plugin;

use Pase\Plugin\Contract\ExtensionPlugin;
use Pase\Support\Logger;

/**
 * Rejestr wtyczek rozszerzających z katalogu extensions/ (obok integrations/ dla integracji).
 *
 * Każde rozszerzenie to extensions/<slug>/register.php zwracający FQCN klasy (PaseExt\<Slug>\...)
 * albo instancję ExtensionPlugin. boot() ładuje włączone rozszerzenia raz na żądanie i wywołuje
 * ich boot() - tam podpinają się pod Hooks. Wadliwe rozszerzenie jest logowane i pomijane.
 */
final class ExtensionRegistry
{
    /** @var array<string,ExtensionPlugin>|null */
    private static ?array $loaded = null;
    private static bool $booting = false;
    private static bool $autoload = false;
    private static ?string $dir = null;

    public static function dir(): string
    {
        return self::$dir ?? PASE_ROOT . '/extensions';
    }

    /** Inny katalog rozszerzeń (testy). Null = domyślny. */
    public static function useDir(?string $dir): void
    {
        self::$dir = $dir;
        self::reset();
    }

    /** Ładuje i uruchamia włączone rozszerzenia (raz na żądanie). */
    public static function boot(): void
    {
        if (self::$loaded !== null || self::$booting || !defined('PASE_ROOT')) {
            return;
        }
        self::$booting = true;
        try {
            self::registerAutoloader();
            $loaded = [];
            foreach (glob(self::dir() . '/*/register.php') ?: [] as $file) {
                if (is_file(dirname($file) . '/.disabled')) {
                    continue;
                }
                $ext = self::loadFile($file);
                if ($ext === null) {
                    continue;
                }
                try {
                    $type = $ext->manifest()->type;
                    if ($type !== basename(dirname($file))) {
                        throw new \UnexpectedValueException("manifest type '{$type}' różni się od nazwy katalogu");
                    }
                    Hooks::bootAs($type, [$ext, 'boot']);
                    $loaded[$type] = $ext;
                } catch (\Throwable $e) {
                    Logger::warn("Rozszerzenie {$file}: " . $e->getMessage());
                }
            }
            self::$loaded = $loaded;
        } finally {
            self::$booting = false;
        }
    }

    /** @return array<string,ExtensionPlugin> uruchomione rozszerzenia, type => instancja */
    public static function all(): array
    {
        self::boot();
        return self::$loaded ?? [];
    }

    /** Wymusza ponowne ładowanie (po instalacji / włączeniu / wyłączeniu). */
    public static function reset(): void
    {
        self::$loaded = null;
        Hooks::reset();
        ExtensionSettings::reset();
    }

    /**
     * Wszystkie zainstalowane rozszerzenia (także wyłączone) - do strony Wtyczki.
     * @return list<array{slug:string,enabled:bool,manifest:?ExtensionManifest,error:?string}>
     */
    public static function installed(): array
    {
        self::registerAutoloader();
        $out = [];
        foreach (glob(self::dir() . '/*/register.php') ?: [] as $file) {
            $manifest = null;
            $error = null;
            try {
                $manifest = self::loadFile($file)?->manifest();
                if ($manifest === null) {
                    $error = 'register.php nie zwraca rozszerzenia (ExtensionPlugin)';
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
            $out[] = ['slug' => basename(dirname($file)), 'enabled' => !is_file(dirname($file) . '/.disabled'),
                      'manifest' => $manifest, 'error' => $error];
        }
        usort($out, static fn($a, $b) => strcmp($a['slug'], $b['slug']));
        return $out;
    }

    /** Ładuje register.php i zwraca rozszerzenie albo null. */
    public static function loadFile(string $file): ?ExtensionPlugin
    {
        self::registerAutoloader();
        try {
            $ret = require $file;
        } catch (\Throwable $e) {
            Logger::warn("Rozszerzenie: błąd ładowania {$file}: " . $e->getMessage());
            return null;
        }
        if (is_string($ret) && class_exists($ret)) {
            $ret = new $ret();
        }
        if ($ret instanceof ExtensionPlugin) {
            return $ret;
        }
        Logger::warn("Rozszerzenie: {$file} nie zwrócił ExtensionPlugin (zwróć instancję lub FQCN klasy).");
        return null;
    }

    /** PaseExt\<Slug>\Klasa -> extensions/<slug>/Klasa.php (jak PasePlugin\ dla integracji). */
    private static function registerAutoloader(): void
    {
        if (self::$autoload) {
            return;
        }
        self::$autoload = true;
        spl_autoload_register(static function (string $class): void {
            if (!str_starts_with($class, 'PaseExt\\')) {
                return;
            }
            $parts = explode('\\', substr($class, 8));
            $parts[0] = strtolower($parts[0]);
            $path = self::dir() . '/' . implode('/', $parts) . '.php';
            if (is_file($path)) {
                require $path;
            }
        });
    }
}
