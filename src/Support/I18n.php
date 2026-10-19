<?php
declare(strict_types=1);

namespace Pase\Support;

/**
 * Lekka warstwa tłumaczeń (bez gettext, działa na shared hostingu).
 *
 * Słowniki to pliki PHP w katalogu lang/ zwracające tablicę ['klucz' => 'tekst'].
 * Domyślny i zapasowy język to polski (pl) - jeśli klucz nie istnieje w wybranym
 * języku, bierzemy z pl, a gdy i tam brak - zwracamy sam klucz (czytelny w UI).
 *
 * Użycie:
 *   I18n::setLocale('en');
 *   echo I18n::t('orders.title');               // tekst
 *   echo I18n::t('orders.found', ['n' => 12]);  // z podstawieniem {n}
 *
 * Globalny skrót t() jest zdefiniowany w config/config.php.
 *
 * Teksty zmienione w panelu (Konfiguracja → Teksty) leżą w tabeli settings pod kluczami
 * "text.<język>.<klucz>" i mają pierwszeństwo nad plikami - config/config.php podaje je
 * przez useOverrides() razem z resztą ustawień (bez dodatkowego zapytania).
 */
final class I18n
{
    public const DEFAULT = 'pl';
    /** Prefiks klucza w tabeli settings dla tekstu zmienionego w panelu. */
    public const OVERRIDE_PREFIX = 'text.';

    private static string $locale = self::DEFAULT;
    /** @var array<string,array<string,string>> wczytane słowniki per locale */
    private static array $dict = [];
    /** @var array<string,array<string,string>> teksty zmienione w panelu per locale */
    private static array $overrides = [];

    /** Języki dostępne (mają plik lang/<code>.php). @return array<string,string> code=>nazwa */
    public static function available(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/*.php') ?: [] as $file) {
            $code = basename($file, '.php');
            $data = self::load($code);
            $out[$code] = $data['_name'] ?? strtoupper($code);
        }
        // Gwarantuj obecność polskiego (zawsze jest fundamentem).
        if (!isset($out[self::DEFAULT])) {
            $out[self::DEFAULT] = 'Polski';
        }
        ksort($out);
        return $out;
    }

    public static function setLocale(string $code): void
    {
        $code = preg_replace('/[^a-z]/', '', strtolower($code)) ?: self::DEFAULT;
        self::$locale = self::isAvailable($code) ? $code : self::DEFAULT;
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    public static function isAvailable(string $code): bool
    {
        return $code === self::DEFAULT || is_file(self::dir() . '/' . $code . '.php');
    }

    /**
     * Tłumaczy klucz. Podstawia {placeholdery} z $vars.
     * @param array<string,string|int|float> $vars
     */
    public static function t(string $key, array $vars = []): string
    {
        $text = self::lookup(self::$locale, $key)
            ?? self::lookup(self::DEFAULT, $key)
            ?? $key;

        if ($vars !== []) {
            $repl = [];
            foreach ($vars as $k => $v) {
                $repl['{' . $k . '}'] = (string) $v;
            }
            $text = strtr($text, $repl);
        }
        return $text;
    }

    /**
     * Wczytuje teksty zmienione w panelu z mapy ustawień (klucz settings => wartość).
     * Pozostałe ustawienia są pomijane; puste wartości nic nie nadpisują.
     * @param array<string,?string> $settings
     */
    public static function useOverrides(array $settings): void
    {
        self::$overrides = [];
        foreach ($settings as $name => $value) {
            $name = (string) $name;
            if (!str_starts_with($name, self::OVERRIDE_PREFIX) || $value === null || $value === '') {
                continue;
            }
            $rest = substr($name, strlen(self::OVERRIDE_PREFIX));
            $dot  = strpos($rest, '.');
            if ($dot === false || $dot === 0) {
                continue;
            }
            self::$overrides[substr($rest, 0, $dot)][substr($rest, $dot + 1)] = (string) $value;
        }
    }

    /** Klucz w tabeli settings dla tekstu $key w języku $locale. */
    public static function overrideKey(string $locale, string $key): string
    {
        return self::OVERRIDE_PREFIX . $locale . '.' . $key;
    }

    /** @return array<string,string> teksty zmienione w panelu dla języka */
    public static function overrides(string $locale): array
    {
        return self::$overrides[$locale] ?? [];
    }

    /**
     * Teksty z pliku słownika (bez zmian z panelu) - wartości domyślne.
     * @return array<string,string>
     */
    public static function dictionary(string $locale): array
    {
        $data = self::load($locale);
        unset($data['_name']);
        return $data;
    }

    /** Nazwy {placeholderów} w tekście, np. ['n', 'order']. @return list<string> */
    public static function placeholders(string $text): array
    {
        preg_match_all('/\{([a-z0-9_]+)\}/i', $text, $m);
        return array_values(array_unique($m[1]));
    }

    /**
     * Porządkuje tekst wpisany w panelu. t() bywa wstawiane do HTML i do JS bez dodatkowej
     * obróbki, więc tekst ma być zwykły: jedna linia, bez znaczników, encji i ukośników, a proste
     * cudzysłowy i apostrofy zamieniamy na typograficzne („…”, ’).
     */
    public static function clean(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        $text = str_replace(['<', '>', '`', '\\'], '', $text);
        do { // encje HTML (&#39; itp.), także sklejane („&&#39;;”)
            $before = $text;
            $text = preg_replace('/&(?=#?[a-z0-9]+;)/i', '', $text) ?? '';
        } while ($text !== $before);
        $text = str_replace("'", '’', $text);
        $open = true;
        $text = preg_replace_callback('/"/', static function () use (&$open): string {
            $q = $open ? '„' : '”';
            $open = !$open;
            return $q;
        }, $text) ?? '';
        return mb_substr(trim($text), 0, 1000);
    }

    /**
     * Wybiera język na podstawie kodu z zamówienia lub nagłówka Accept-Language.
     * Zwraca dostępny kod (z fallbackiem na DEFAULT).
     */
    public static function detect(?string $preferred = null, ?string $acceptLanguage = null): string
    {
        // 1) Jawnie podany (np. z payloadu zamówienia: billing.country/locale).
        if ($preferred) {
            $c = self::normalize($preferred);
            if (self::isAvailable($c)) {
                return $c;
            }
        }
        // 2) Accept-Language: "en-US,en;q=0.9,pl;q=0.8".
        if ($acceptLanguage) {
            foreach (explode(',', $acceptLanguage) as $part) {
                $code = self::normalize(trim(explode(';', $part)[0]));
                if (self::isAvailable($code)) {
                    return $code;
                }
            }
        }
        return self::DEFAULT;
    }

    /** Sprowadza "en-US" / "EN" / "pl_PL" do "en"/"pl". */
    private static function normalize(string $s): string
    {
        $s = strtolower($s);
        $s = preg_replace('/[_-].*$/', '', $s) ?? $s; // "en-us" -> "en"
        return preg_replace('/[^a-z]/', '', $s) ?: self::DEFAULT;
    }

    private static function lookup(string $locale, string $key): ?string
    {
        if (isset(self::$overrides[$locale][$key])) {
            return self::$overrides[$locale][$key];
        }
        $data = self::load($locale);
        return $data[$key] ?? null;
    }

    /** @return array<string,string> */
    private static function load(string $locale): array
    {
        if (isset(self::$dict[$locale])) {
            return self::$dict[$locale];
        }
        $file = self::dir() . '/' . $locale . '.php';
        $data = is_file($file) ? (require $file) : [];
        if (!is_array($data)) {
            $data = [];
        }
        return self::$dict[$locale] = $data;
    }

    private static function dir(): string
    {
        return PASE_ROOT . '/lang';
    }
}
