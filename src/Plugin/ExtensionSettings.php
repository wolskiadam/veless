<?php
declare(strict_types=1);

namespace Pase\Plugin;

use Pase\Repository\SettingsRepository;
use Pase\Support\Runtime;

/**
 * Ustawienia rozszerzeń: JSON w tabeli settings pod kluczem EXT_<TYPE>.
 * Formularz na stronie Konfiguracja → Wtyczki zapisuje tu pola z manifestu.
 */
final class ExtensionSettings
{
    /** @var array<string,array<string,mixed>> */
    private static array $cache = [];

    public static function key(string $type): string
    {
        return 'EXT_' . strtoupper($type);
    }

    /** @return array<string,mixed> */
    public static function get(string $type, ?\PDO $pdo = null): array
    {
        if (isset(self::$cache[$type])) {
            return self::$cache[$type];
        }
        try {
            $raw = (new SettingsRepository($pdo ?? Runtime::pdo()))->get(self::key($type));
        } catch (\Throwable) {
            return [];
        }
        $val = json_decode((string) $raw, true);
        return self::$cache[$type] = is_array($val) ? $val : [];
    }

    /**
     * Zapis z formularza: tylko pola z manifestu; puste pole sekretu nie nadpisuje zapisanego.
     * @param array<string,mixed> $posted
     */
    public static function save(\PDO $pdo, ExtensionManifest $m, array $posted): void
    {
        $old = self::get($m->type, $pdo);
        $clean = [];
        foreach ($m->fields as $f) {
            $k = (string) ($f['key'] ?? '');
            if ($k === '') {
                continue;
            }
            $type = (string) ($f['type'] ?? 'text');
            $v = $type === 'checkbox' ? (!empty($posted[$k]) ? '1' : '0') : trim((string) ($posted[$k] ?? ''));
            if ($type === 'select' && $v !== '' && !array_key_exists($v, (array) ($f['options'] ?? []))) {
                $v = '';
            }
            if (!empty($f['secret']) && $v === '') {
                $v = (string) ($old[$k] ?? '');
            }
            $clean[$k] = $v;
        }
        (new SettingsRepository($pdo))->setMany([self::key($m->type) => json_encode($clean, JSON_UNESCAPED_UNICODE)]);
        self::$cache[$m->type] = $clean;
    }

    public static function reset(): void
    {
        self::$cache = [];
    }
}
