<?php
declare(strict_types=1);

namespace Pase\Plugin;

use Pase\Support\Logger;

/**
 * Haki dla wtyczek rozszerzających (jak w WordPressie): filtry zmieniają dane, akcje reagują na zdarzenia.
 *
 * Rozszerzenie podpina się w boot():
 *   Hooks::addFilter('orders.columns', fn(array $cols) => $cols + ['waga' => [...]]);
 *   Hooks::addAction('packing.packed', fn(int $id, array $order) => ...);
 *
 * Rdzeń woła applyFilters()/doAction() w nazwanych miejscach (lista: docs/EXTENSIONS.md).
 * Rozszerzenia ładują się same przy pierwszym użyciu haka (ExtensionRegistry::boot()).
 *
 * Błąd w haku nie wywraca strony: jest logowany, filtr zwraca wartość sprzed wadliwego
 * callbacku, a reszta haków działa dalej.
 */
final class Hooks
{
    /** @var array<string,array<int,list<array{0:callable,1:string}>>> nazwa => priorytet => [callback, właściciel] */
    private static array $filters = [];
    /** @var array<string,array<int,list<array{0:callable,1:string}>>> */
    private static array $actions = [];
    /** @var array<string,array<string,mixed>> klucz "rozszerzenie.id" => definicja strony */
    private static array $pages = [];
    /** @var array<string,array<string,mixed>> nazwa akcji pakowania => definicja */
    private static array $packingActions = [];
    /** @var array<string,list<array{type:string,file:string,owner:string}>> 'admin'|'packing' => pliki */
    private static array $assets = [];

    /** Rozszerzenie, którego boot() właśnie trwa (do opisu błędów i kluczy stron). */
    private static string $owner = '';

    public static function addFilter(string $name, callable $callback, int $priority = 10): void
    {
        self::$filters[$name][$priority][] = [$callback, self::$owner];
    }

    public static function addAction(string $name, callable $callback, int $priority = 10): void
    {
        self::$actions[$name][$priority][] = [$callback, self::$owner];
    }

    /** Przepuszcza $value przez wszystkie filtry $name (niższy priorytet pierwszy). */
    public static function applyFilters(string $name, mixed $value, mixed ...$args): mixed
    {
        ExtensionRegistry::boot();
        foreach (self::ordered(self::$filters[$name] ?? []) as [$callback, $owner]) {
            try {
                $next = $callback($value, ...$args);
                // Filtr musi oddać ten sam typ (np. tablicę kolumn) - inaczej rdzeń dostałby śmieci.
                if ($value !== null && get_debug_type($next) !== get_debug_type($value)) {
                    throw new \UnexpectedValueException('filtr zwrócił ' . get_debug_type($next) . ' zamiast ' . get_debug_type($value));
                }
                $value = $next;
            } catch (\Throwable $e) {
                self::report($name, $owner, $e);
            }
        }
        return $value;
    }

    public static function doAction(string $name, mixed ...$args): void
    {
        ExtensionRegistry::boot();
        foreach (self::ordered(self::$actions[$name] ?? []) as [$callback, $owner]) {
            try {
                $callback(...$args);
            } catch (\Throwable $e) {
                self::report($name, $owner, $e);
            }
        }
    }

    /**
     * Akcja, której haki wypisują HTML (echo) - zwraca zebrany wynik. Błąd jednego haka
     * odrzuca tylko jego wyjście.
     */
    public static function render(string $name, mixed ...$args): string
    {
        ExtensionRegistry::boot();
        $out = '';
        foreach (self::ordered(self::$actions[$name] ?? []) as [$callback, $owner]) {
            ob_start();
            try {
                $ret = $callback(...$args);
                $out .= ob_get_clean() . (is_string($ret) ? $ret : '');
            } catch (\Throwable $e) {
                ob_end_clean();
                self::report($name, $owner, $e);
            }
        }
        return $out;
    }

    /**
     * Wywołuje callback rozszerzenia (np. komórkę kolumny, treść sekcji) - echo i zwrócony
     * tekst są sklejane; błąd daje null i wpis w logu zamiast przerwanej strony.
     */
    public static function safe(string $where, callable $callback, mixed ...$args): mixed
    {
        ob_start();
        try {
            $ret = $callback(...$args);
            $echoed = ob_get_clean();
            return $echoed !== '' ? $echoed . (is_string($ret) ? $ret : '') : $ret;
        } catch (\Throwable $e) {
            ob_end_clean();
            self::report($where, '', $e);
            return null;
        }
    }

    public static function hasFilter(string $name): bool
    {
        ExtensionRegistry::boot();
        return !empty(self::$filters[$name]);
    }

    public static function hasAction(string $name): bool
    {
        ExtensionRegistry::boot();
        return !empty(self::$actions[$name]);
    }

    /**
     * Własna strona w panelu: ext.php?page=<rozszerzenie>.<id>.
     *
     * @param array{title:string,render:callable,group?:string,role?:string,menu?:bool} $def
     *   render(PDO $pdo, array $ctx): string|void - treść strony (HTML; może też echo),
     *   group - grupa menu (orders, catalog, marketplace, config, system), domyślnie 'system',
     *   role  - kto widzi: viewer, editor (domyślnie) albo admin,
     *   menu  - false = strona bez pozycji w menu (np. formularz otwierany z karty zamówienia).
     */
    public static function addPage(string $id, array $def): void
    {
        $owner = self::$owner !== '' ? self::$owner : 'core';
        if (!preg_match('/^[a-z0-9_]+$/', $id) || !isset($def['title'], $def['render']) || !is_callable($def['render'])) {
            Logger::warn("Rozszerzenie {$owner}: nieprawidłowa strona '{$id}' (id a-z0-9_, wymagane title i render).");
            return;
        }
        $key = $owner . '.' . $id;
        self::$pages[$key] = $def + ['key' => $key, 'owner' => $owner, 'group' => 'system', 'role' => 'editor', 'menu' => true];
    }

    /** @return array<string,array<string,mixed>> */
    public static function pages(): array
    {
        ExtensionRegistry::boot();
        return self::$pages;
    }

    public static function page(string $key): ?array
    {
        return self::pages()[$key] ?? null;
    }

    /** Adres strony rozszerzenia (z panelu, katalog public/admin). */
    public static function pageUrl(string $key, array $query = []): string
    {
        return 'ext.php?' . http_build_query(['page' => $key] + $query);
    }

    /**
     * Akcja API asystenta pakowania (POST z JS: PackExt.api('nazwa', {...})).
     *
     * @param array{run:callable,write?:bool} $def run(int $orderId, array $in, array $ctx): array - wynik do JS;
     *   write (domyślnie true) - wymaga uprawnienia do edycji pakowania.
     */
    public static function addPackingAction(string $name, array $def): void
    {
        if (!preg_match('/^[a-z0-9_]+$/', $name) || !is_callable($def['run'] ?? null)) {
            Logger::warn('Rozszerzenie ' . self::$owner . ": nieprawidłowa akcja pakowania '{$name}'.");
            return;
        }
        self::$packingActions['ext_' . $name] = $def + ['write' => true, 'owner' => self::$owner];
    }

    /** Definicja akcji pakowania po pełnej nazwie (z prefiksem ext_). */
    public static function packingAction(string $action): ?array
    {
        ExtensionRegistry::boot();
        return self::$packingActions[$action] ?? null;
    }

    /**
     * Plik CSS/JS rozszerzenia wstawiany na strony: 'admin' (każda strona panelu) albo
     * 'packing' (asystent pakowania - panel i telefon). Treść trafia do strony w całości.
     */
    public static function addAsset(string $where, string $file): void
    {
        $type = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($where, ['admin', 'packing'], true) || !in_array($type, ['css', 'js'], true) || !is_file($file)) {
            Logger::warn('Rozszerzenie ' . self::$owner . ": pominięto plik '{$file}' ({$where}).");
            return;
        }
        self::$assets[$where][] = ['type' => $type, 'file' => $file, 'owner' => self::$owner];
    }

    /** Znaczniki <style>/<script> z plikami rozszerzeń dla danego miejsca. */
    public static function assetTags(string $where): string
    {
        ExtensionRegistry::boot();
        $out = '';
        foreach (self::$assets[$where] ?? [] as $a) {
            $code = (string) @file_get_contents($a['file']);
            $attr = ' data-ext="' . htmlspecialchars($a['owner']) . '"';
            $out .= $a['type'] === 'css'
                ? '<style' . $attr . '>' . str_ireplace('</style', '<\/style', $code) . "</style>\n"
                : '<script' . $attr . '>' . str_ireplace('</script', '<\/script', $code) . "</script>\n";
        }
        return $out;
    }

    /** Uruchamia boot() rozszerzenia z przypisaniem rejestrowanych haków do niego. */
    public static function bootAs(string $owner, callable $boot): void
    {
        $prev = self::$owner;
        self::$owner = $owner;
        try {
            $boot();
        } finally {
            self::$owner = $prev;
        }
    }

    /** Usuwa wszystkie haki (testy, ponowne ładowanie po zmianie wtyczek). */
    public static function reset(): void
    {
        self::$filters = self::$actions = self::$pages = self::$packingActions = self::$assets = [];
    }

    /** @param array<int,list<array{0:callable,1:string}>> $byPriority @return list<array{0:callable,1:string}> */
    private static function ordered(array $byPriority): array
    {
        if ($byPriority === []) {
            return [];
        }
        ksort($byPriority);
        return array_merge(...array_values($byPriority));
    }

    private static function report(string $hook, string $owner, \Throwable $e): void
    {
        Logger::warn('Rozszerzenie ' . ($owner !== '' ? $owner : '?') . " - błąd w haku '{$hook}': " . $e->getMessage()
            . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    }
}
