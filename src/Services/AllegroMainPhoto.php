<?php
declare(strict_types=1);

namespace Pase\Services;

/**
 * Zdjęcie główne oferty Allegro w zalecanym rozmiarze 2560×2560: kwadrat z białym tłem, zdjęcie w całości
 * (bez przycinania), wyśrodkowane. Działa na GD.
 */
final class AllegroMainPhoto
{
    public const SIZE = 2560;

    /** Ustawienie: adres zdjęcia źródłowego => adres gotowego zdjęcia 2560×2560 na serwerach Allegro. */
    public const CACHE_SETTING = 'allegro_main_photo_cache';

    /** Poniżej tej wielkości (dłuższy bok) powiększenie do 2560 może być nieostre - ostrzegamy. */
    public const SMALL = 1200;

    public static function available(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagejpeg');
    }

    /** @return array{0:int,1:int}|null szerokość i wysokość zdjęcia */
    public static function dimensions(string $bytes): ?array
    {
        $s = @getimagesizefromstring($bytes);
        return is_array($s) && $s[0] > 0 && $s[1] > 0 ? [(int) $s[0], (int) $s[1]] : null;
    }

    public static function isReady(int $w, int $h): bool
    {
        return $w === self::SIZE && $h === self::SIZE;
    }

    /**
     * Zdjęcie -> JPEG size×size: dłuższy bok = size, reszta dopełniona białym tłem (przezroczystość też na biało).
     * @return string|null JPEG albo null, gdy nie da się odczytać zdjęcia
     */
    public static function square(string $bytes, int $size = self::SIZE): ?string
    {
        if (!self::available()) {
            return null;
        }
        $dim = self::dimensions($bytes);
        if ($dim === null || $dim[0] * $dim[1] > 60_000_000) {
            return null;
        }
        @ini_set('memory_limit', '512M');
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return null;
        }
        [$w, $h] = [imagesx($src), imagesy($src)];
        $scale = $size / max($w, $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($size, $size);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagealphablending($dst, true);
        imagecopyresampled($dst, $src, (int) (($size - $nw) / 2), (int) (($size - $nh) / 2), 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);
        ob_start();
        imagejpeg($dst, null, 90);
        imagedestroy($dst);
        return (string) ob_get_clean();
    }

    /**
     * Czy adres zdjęcia wolno pobrać przez CRM : tylko http(s)
     * z hostów, z których pochodzą zdjęcia produktów, albo z serwerów zdjęć Allegro.
     *
     * @param string[] $knownHosts hosty zdjęć produktów
     */
    public static function urlAllowed(string $url, array $knownHosts): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || isset($parts['user']) || isset($parts['port'])) {
            return false;
        }
        if ($host === 'allegroimg.com' || str_ends_with($host, '.allegroimg.com')) {
            return true;
        }
        return in_array($host, array_map('strtolower', $knownHosts), true);
    }
}
