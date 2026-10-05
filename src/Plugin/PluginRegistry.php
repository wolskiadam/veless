<?php
declare(strict_types=1);

namespace Pase\Plugin;

use Pase\Plugin\Contract\IntegrationPlugin;
use Pase\Support\Logger;

/**
 * Rejestr wtyczek integracji z AUTO-DISCOVERY z katalogu integrations/.
 *
 * Każda wtyczka to podkatalog integrations/<vendor>/ z plikiem `register.php`,
 * który ZWRACA nazwę klasy wtyczki (FQCN, implementującej IntegrationPlugin)
 * albo gotową instancję. Rejestr ładuje wszystkie, indeksuje po manifest()->type.
 *
 * Discovery jest leniwe i odporne na błędy: wadliwa wtyczka jest logowana
 * i pomijana - nie wywala całego systemu.
 */
final class PluginRegistry
{
    /** @var array<string,IntegrationPlugin>|null type => instancja (prototyp, bez configu) */
    private static ?array $plugins = null;

    public static function dir(): string
    {
        return PASE_ROOT . '/integrations';
    }

    /** Wymusza ponowne skanowanie (np. po wgraniu nowej wtyczki). */
    public static function reset(): void
    {
        self::$plugins = null;
    }

    /** @return array<string,IntegrationPlugin> wszystkie poprawne wtyczki, type => instancja */
    public static function all(): array
    {
        if (self::$plugins !== null) {
            return self::$plugins;
        }
        $found = [];
        $dir = self::dir();
        if (is_dir($dir)) {
            foreach (glob($dir . '/*/register.php') ?: [] as $file) {
                // Pomijamy wtyczki wyłączone (znacznik .disabled w katalogu).
                if (is_file(dirname($file) . '/.disabled')) {
                    continue;
                }
                $plugin = self::loadFile($file);
                if ($plugin === null) {
                    continue;
                }
                try {
                    $type = $plugin->manifest()->type;
                } catch (\Throwable $e) {
                    Logger::warn("Plugin: manifest() rzucił wyjątek w {$file}: " . $e->getMessage());
                    continue;
                }
                if (!preg_match('/^[a-z0-9_]+$/', $type)) {
                    Logger::warn("Plugin: nieprawidłowy type '{$type}' w {$file} (dozwolone a-z0-9_).");
                    continue;
                }
                if (isset($found[$type])) {
                    Logger::warn("Plugin: zduplikowany type '{$type}' w {$file} - pomijam.");
                    continue;
                }
                $found[$type] = $plugin;
            }
        }
        self::$plugins = $found;
        return $found;
    }

    /** Prototyp wtyczki danego typu (bez configu). */
    public static function get(string $type): ?IntegrationPlugin
    {
        return self::all()[$type] ?? null;
    }

    /**
     * Lista WSZYSTKICH zainstalowanych wtyczek (także wyłączonych) - do strony zarządzania.
     * @return array<int,array{slug:string,enabled:bool,manifest:?PluginManifest,error:?string}>
     */
    public static function installed(): array
    {
        $out = [];
        $dir = self::dir();
        foreach (glob($dir . '/*/register.php') ?: [] as $file) {
            $slug    = basename(dirname($file));
            $enabled = !is_file(dirname($file) . '/.disabled');
            $manifest = null;
            $error = null;
            try {
                $plugin = self::loadFile($file);
                $manifest = $plugin?->manifest();
                if ($plugin === null) {
                    $error = 'register.php nie zwraca poprawnej wtyczki';
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
            $out[] = ['slug' => $slug, 'enabled' => $enabled, 'manifest' => $manifest, 'error' => $error];
        }
        usort($out, static fn($a, $b) => strcmp($a['slug'], $b['slug']));
        return $out;
    }

    /** Wtyczka typu związana z configiem konta. Null gdy brak wtyczki. */
    public static function forAccount(string $type, array $config): ?IntegrationPlugin
    {
        $proto = self::get($type);
        return $proto?->withConfig($config);
    }

    /** @return array<int,PluginManifest> manifesty wszystkich wtyczek (do UI). */
    public static function manifests(): array
    {
        $out = [];
        foreach (self::all() as $p) {
            try {
                $out[] = $p->manifest();
            } catch (\Throwable $e) {
                // pomiń wadliwą
            }
        }
        return $out;
    }

    /** Wtyczki deklarujące daną zdolność (Capability::*). @return array<string,PluginManifest> */
    public static function withCapability(string $cap): array
    {
        $out = [];
        foreach (self::manifests() as $m) {
            if ($m->hasCapability($cap)) {
                $out[$m->type] = $m;
            }
        }
        return $out;
    }

    /** Ładuje plugin.php i zwraca instancję IntegrationPlugin albo null. */
    private static function loadFile(string $file): ?IntegrationPlugin
    {
        try {
            $ret = require $file;
        } catch (\Throwable $e) {
            Logger::warn("Plugin: błąd ładowania {$file}: " . $e->getMessage());
            return null;
        }
        // register.php może zwrócić instancję albo nazwę klasy.
        if ($ret instanceof IntegrationPlugin) {
            return $ret;
        }
        if (is_string($ret) && class_exists($ret)) {
            $obj = new $ret();
            if ($obj instanceof IntegrationPlugin) {
                return $obj;
            }
        }
        Logger::warn("Plugin: {$file} nie zwrócił IntegrationPlugin (zwróć instancję lub FQCN klasy).");
        return null;
    }
}
