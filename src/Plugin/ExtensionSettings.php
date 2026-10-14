<?php
declare(strict_types=1);

namespace Pase\Plugin;

use Pase\Repository\SettingsRepository;
use Pase\Support\Runtime;
use Pase\Support\SecretStore;

/**
 * Ustawienia rozszerzeń: JSON w tabeli settings pod kluczem EXT_<TYPE>.
 * Formularz na stronie Konfiguracja → Wtyczki zapisuje tu pola z manifestu.
 * Pola 'secret' => true (tokeny, klucze API) są w JSON zaszyfrowane (SecretStore), a get() oddaje je odszyfrowane -
 * rozszerzenie czyta je zwykłym $this->setting() i nie dotyka klucza szyfrowania.
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
        $val = is_array($val) ? $val : [];
        foreach ($val as $k => $v) {
            if (SecretStore::isSealed($v)) {
                $val[$k] = (string) SecretStore::reveal($v, self::context($type, (string) $k));
            }
        }
        return self::$cache[$type] = $val;
    }

    /**
     * Zapis z formularza: tylko pola z manifestu; puste pole sekretu nie nadpisuje zapisanego
     * (chyba że jest w $clear - pole „usuń zapisany”). Sekrety trafiają do bazy zaszyfrowane.
     * @param array<string,mixed> $posted
     * @param array<string,mixed> $clear klucze sekretów do wyczyszczenia
     */
    public static function save(\PDO $pdo, ExtensionManifest $m, array $posted, array $clear = []): void
    {
        $old = self::get($m->type, $pdo);
        $stored = [];
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
            if (!empty($f['secret'])) {
                if ($v === '' && empty($clear[$k])) {
                    $v = (string) ($old[$k] ?? '');
                }
                $clean[$k] = $v;
                $stored[$k] = SecretStore::seal($v, self::context($m->type, $k));
                continue;
            }
            $clean[$k] = $stored[$k] = $v;
        }
        (new SettingsRepository($pdo))->setMany([self::key($m->type) => json_encode($stored, JSON_UNESCAPED_UNICODE)]);
        self::$cache[$m->type] = $clean;
    }

    private static function context(string $type, string $field): string
    {
        return 'ext.' . $type . '.' . $field;
    }

    public static function reset(): void
    {
        self::$cache = [];
    }
}
