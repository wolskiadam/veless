<?php
declare(strict_types=1);

namespace Pase\Support;

use PDO;

/**
 * Kolorystyka systemu (Konfiguracja → Wygląd): kolor akcentu (przyciski, linki, zaznaczenia)
 * i odcień tła. Jedno ustawienie na instalację (tabela settings, klucz ui_theme), więc każda
 * firma, która stawia system u siebie, dopasowuje go do swojej marki.
 *
 * Wszystkie style panelu korzystają ze zmiennych CSS (--accent, --accent-soft, --bg, ...),
 * więc wystarczy nadpisać je w :root - z jednego koloru wyliczamy resztę odcieni.
 * Na przyciskach w akcencie jest biały napis, dlatego zbyt jasny kolor przyciemniamy
 * do kontrastu co najmniej 3:1 z bielą (WCAG dla elementów interfejsu i pogrubionego tekstu).
 */
final class Theme
{
    public const SETTING = 'ui_theme';
    public const DEFAULT_ACCENT = '#9c6b2e';
    public const DEFAULT_TONE = 'warm';
    private const MIN_CONTRAST = 3.0;

    /** Gotowe kolory akcentu: klucz => [nazwa, kolor]. */
    public const PRESETS = [
        'amber'  => ['Bursztyn', '#9c6b2e'],
        'navy'   => ['Granat', '#2f5bb7'],
        'teal'   => ['Morski', '#0f7c86'],
        'green'  => ['Zieleń', '#2f7d5b'],
        'violet' => ['Fiolet', '#6d4bc3'],
        'berry'  => ['Malina', '#b8336a'],
        'red'    => ['Czerwień', '#c0392b'],
        'slate'  => ['Grafit', '#474c55'],
    ];

    /** Odcienie tła i linii: klucz => [nazwa, --bg, --surface-2, --line]. */
    public const TONES = [
        'warm'    => ['Ciepłe (kremowe)', '#f6f5f2', '#fbfaf7', '#e6e3da'],
        'neutral' => ['Neutralne (szare)', '#f5f6f7', '#fafafb', '#e3e5e8'],
        'cool'    => ['Chłodne (niebieskawe)', '#f3f6fa', '#f8fafd', '#dde4ee'],
    ];

    /** @var array{accent:string,tone:string}|null */
    private static ?array $cache = null;

    /** @return array{accent:string,tone:string} */
    public static function defaults(): array
    {
        return ['accent' => self::DEFAULT_ACCENT, 'tone' => self::DEFAULT_TONE];
    }

    /**
     * Poprawne ustawienia z dowolnych danych (formularz, JSON z bazy).
     * @return array{accent:string,tone:string}
     */
    public static function normalize(mixed $data): array
    {
        $data = is_array($data) ? $data : [];
        $accent = self::hex((string) ($data['accent'] ?? '')) ?? self::DEFAULT_ACCENT;
        $tone = (string) ($data['tone'] ?? '');
        return [
            'accent' => self::readable($accent),
            'tone'   => isset(self::TONES[$tone]) ? $tone : self::DEFAULT_TONE,
        ];
    }

    /** @return array{accent:string,tone:string} */
    public static function load(?PDO $pdo): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        if ($pdo === null) {
            return self::defaults();
        }
        try {
            $st = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
            $st->execute([self::SETTING]);
            $raw = $st->fetchColumn();
        } catch (\Throwable) {
            return self::defaults(); // brak tabeli (instalator) - kolory domyślne
        }
        return self::$cache = self::normalize(is_string($raw) ? json_decode($raw, true) : null);
    }

    /** @param array{accent:string,tone:string} $theme */
    public static function save(PDO $pdo, array $theme): array
    {
        $theme = self::normalize($theme);
        (new \Pase\Repository\SettingsRepository($pdo))->setMany([
            self::SETTING => $theme === self::defaults() ? '' : json_encode($theme),
        ]);
        return self::$cache = $theme;
    }

    /** Kolor akcentu (np. do <meta name="theme-color"> i manifestu aplikacji). */
    public static function accent(?PDO $pdo): string
    {
        return self::load($pdo)['accent'];
    }

    /**
     * Zmienne CSS nadpisujące domyślne (':root{...}') albo '' przy kolorach domyślnych.
     * @param array{accent:string,tone:string}|null $theme
     */
    public static function css(?array $theme): string
    {
        $theme = self::normalize($theme);
        if ($theme === self::defaults()) {
            return '';
        }
        return ':root{' . implode(';', self::vars($theme)) . '}';
    }

    /** <style> z nadpisaniem kolorów dla bieżącej instalacji albo ''. */
    public static function styleTag(?PDO $pdo): string
    {
        $css = self::css(self::load($pdo));
        return $css === '' ? '' : '<style id="ui-theme">' . $css . '</style>';
    }

    /**
     * @param array{accent:string,tone:string} $theme
     * @return list<string>
     */
    public static function vars(array $theme): array
    {
        $ac = $theme['accent'];
        [, $bg, $surface2, $line] = self::TONES[$theme['tone']] ?? self::TONES[self::DEFAULT_TONE];
        return [
            "--accent:{$ac}",
            '--accent-hover:' . self::mix($ac, '#000000', 0.16),
            '--accent-soft:' . self::mix($ac, '#ffffff', 0.84),
            '--accent-ink:' . self::mix($ac, '#000000', 0.42),
            "--bg:{$bg}",
            "--surface-2:{$surface2}",
            "--line:{$line}",
        ];
    }

    /** Kolor przyciemniony tak, żeby biały napis był czytelny (kontrast >= 3:1); ciemne kolory bez zmian. */
    public static function readable(string $hex): string
    {
        $c = $hex;
        for ($i = 0; $i < 40 && self::contrastWithWhite($c) < self::MIN_CONTRAST; $i++) {
            $c = self::mix($c, '#000000', 0.05);
        }
        return $c;
    }

    /** „#9C6B2E" / „9c6b2e" / „#abc" -> „#9c6b2e"; niepoprawne -> null. */
    public static function hex(string $c): ?string
    {
        $c = strtolower(ltrim(trim($c), '#'));
        if (preg_match('/^[0-9a-f]{3}$/', $c)) {
            $c = $c[0] . $c[0] . $c[1] . $c[1] . $c[2] . $c[2];
        }
        return preg_match('/^[0-9a-f]{6}$/', $c) ? '#' . $c : null;
    }

    public static function contrastWithWhite(string $hex): float
    {
        return 1.05 / (self::luminance($hex) + 0.05);
    }

    /** Tylko dla testów: zapomnij odczyt z bazy. */
    public static function resetCache(): void
    {
        self::$cache = null;
    }

    private static function luminance(string $hex): float
    {
        $lin = static function (int $v): float {
            $s = $v / 255;
            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        };
        [$r, $g, $b] = self::rgb($hex);
        return 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);
    }

    /** @return array{int,int,int} */
    private static function rgb(string $hex): array
    {
        return [(int) hexdec(substr($hex, 1, 2)), (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2))];
    }

    private static function mix(string $a, string $b, float $t): string
    {
        [$r1, $g1, $b1] = self::rgb($a);
        [$r2, $g2, $b2] = self::rgb($b);
        $m = static fn(int $x, int $y): int => (int) round($x + ($y - $x) * $t);
        return sprintf('#%02x%02x%02x', $m($r1, $r2), $m($g1, $g2), $m($b1, $b2));
    }
}
